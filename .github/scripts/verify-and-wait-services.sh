#!/usr/bin/env bash
set -euo pipefail

services_json="${INTEGRATION_SERVICES:-[]}"
topologies_json="${SERVICE_TOPOLOGIES-}"
[ -n "$topologies_json" ] || topologies_json='{}'
catalog_file="${PHPFORGE_SERVICE_CATALOG:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/resources/services/catalog.php}"

if ! jq -e 'type == "array" and all(.[]; type == "string")' >/dev/null <<< "$services_json"; then
  echo "::error::INTEGRATION_SERVICES must be a JSON string array."
  exit 1
fi

if ! jq -e 'type == "object" and all(.[]; type == "string")' >/dev/null <<< "$topologies_json"; then
  echo "::error::SERVICE_TOPOLOGIES must be a JSON object."
  exit 1
fi

wait_for() {
  local name="$1"
  local probe="$2"
  local diagnostic=""
  shift 2

  for ((_attempt = 1; _attempt <= retry_attempts; _attempt++)); do
    if diagnostic="$(php -r "$probe" "$@" 2>&1)"; then
      echo "${name} ready"
      return 0
    fi

    sleep 1
  done

  echo "::error::${name} service not ready"
  diagnostic="${diagnostic//$'\r'/ }"
  diagnostic="${diagnostic//$'\n'/ }"
  if [[ -n "$diagnostic" ]]; then
    echo "::error::Last ${name} probe error: ${diagnostic:0:1000}"
  fi
  return 1
}

required_extensions="$(INTEGRATION_SERVICES="$services_json" php -r '
$catalog = require $argv[1];
$services = json_decode((string) getenv("INTEGRATION_SERVICES"), true, 512, JSON_THROW_ON_ERROR);
$required = [];
foreach ($services as $service) {
    if (!isset($catalog[$service])) {
        fwrite(STDERR, "Unknown integration service: {$service}\n");
        exit(2);
    }
    foreach ($catalog[$service]["extensions"] as $extension) {
        $required[$extension] = true;
    }
}
echo implode("\n", array_keys($required));
' "$catalog_file")"

missing=()

while IFS= read -r extension; do
  [ -z "$extension" ] && continue

  if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension"; then
    missing+=("$extension")
  fi
done <<< "$required_extensions"

if [ "${#missing[@]}" -gt 0 ]; then
  echo "::error::Missing required PHP extensions for selected services: ${missing[*]}"
  exit 1
fi

while IFS= read -r service; do
  topology="$(jq -r --arg service "$service" '.[$service] // "standalone"' <<< "$topologies_json")"
  probe="$(php -r '$catalog = require $argv[1]; echo $catalog[$argv[2]]["probe"] ?? "";' "$catalog_file" "$service")"
  retry_attempts="$(php -r '$catalog = require $argv[1]; echo $catalog[$argv[2]]["retry_attempts"] ?? 60;' "$catalog_file" "$service")"

  case "$probe" in
    redis)
      wait_for redis '$r = new Redis(); try { $r->connect(getenv("IC_REDIS_HOST"), (int) getenv("IC_REDIS_PORT"), 0.5); $pass = getenv("IC_REDIS_PASSWORD"); if (is_string($pass) && $pass !== "" && !$r->auth($pass)) { exit(1); } $pong = $r->ping(); exit($pong === true || stripos((string) $pong, "pong") !== false ? 0 : 1); } catch (Throwable) { exit(1); }'
      ;;
    valkey)
      wait_for valkey '$r = new Redis(); try { $r->connect(getenv("IC_VALKEY_HOST"), (int) getenv("IC_VALKEY_PORT"), 0.5); $pass = getenv("IC_VALKEY_PASSWORD"); if (is_string($pass) && $pass !== "" && !$r->auth($pass)) { exit(1); } $pong = $r->ping(); exit($pong === true || stripos((string) $pong, "pong") !== false ? 0 : 1); } catch (Throwable) { exit(1); }'
      ;;
    memcached)
      wait_for memcached '$m = new Memcached(); $m->addServer(getenv("IC_MEMCACHED_HOST"), (int) getenv("IC_MEMCACHED_PORT")); $key = "phpforge_probe_" . bin2hex(random_bytes(4)); if (!$m->set($key, "ok", 5) || $m->get($key) !== "ok") { exit(1); } $m->delete($key);'
      ;;
    mysql|mariadb|postgres|mssql)
      upper="${service^^}"
      dsn_name="IC_${upper}_DSN"
      user_name="IC_${upper}_USER"
      password_name="IC_${upper}_PASSWORD"
      wait_for "$service" '$dsn = getenv($argv[1]); $user = getenv($argv[2]); $pass = getenv($argv[3]); try { $pdo = new PDO((string) $dsn, (string) $user, (string) $pass); exit($pdo->query("SELECT 1") === false ? 1 : 0); } catch (Throwable $error) { fwrite(STDERR, $error::class . ": " . $error->getMessage()); exit(1); }' "$dsn_name" "$user_name" "$password_name"
      ;;
    sqlite)
      wait_for sqlite '$memory = new PDO((string) getenv("IC_SQLITE_MEMORY_DSN")); $file = new PDO((string) getenv("IC_SQLITE_FILE_DSN")); exit($memory->query("SELECT 1") !== false && $file->query("SELECT 1") !== false ? 0 : 1);'
      ;;
    mongodb)
      wait_for mongodb '$manager = new MongoDB\Driver\Manager((string) getenv("IC_MONGODB_DSN")); try { $result = $manager->executeCommand("admin", new MongoDB\Driver\Command(["ping" => 1]))->toArray()[0] ?? null; exit(is_object($result) && ($result->ok ?? 0) == 1 ? 0 : 1); } catch (Throwable) { exit(1); }'
      if [ "$topology" = "replica-set" ]; then
        wait_for mongodb-replica-set '$manager = new MongoDB\Driver\Manager((string) getenv("IC_MONGODB_DSN")); try { $row = $manager->executeCommand("admin", new MongoDB\Driver\Command(["replSetGetStatus" => 1]))->toArray()[0] ?? null; $value = is_object($row) ? ($row->members ?? null) : null; $members = is_iterable($value) ? iterator_to_array($value) : []; $primary = array_filter($members, static fn($member): bool => is_object($member) && ($member->stateStr ?? "") === "PRIMARY"); exit(count($members) >= 2 && count($primary) === 1 ? 0 : 1); } catch (Throwable) { exit(1); }'
      fi
      ;;
    rabbitmq)
      wait_for rabbitmq '$url = rtrim((string) getenv("IC_RABBITMQ_MANAGEMENT_URL"), "/") . "/api/health/checks/alarms"; $auth = base64_encode((string) getenv("IC_SERVICE_USERNAME") . ":" . (string) getenv("IC_SERVICE_PASSWORD")); $context = stream_context_create(["http" => ["timeout" => 1, "header" => "Authorization: Basic {$auth}\r\n"]]); $payload = @file_get_contents($url, false, $context); $data = is_string($payload) ? json_decode($payload, true) : null; exit(is_array($data) && ($data["status"] ?? null) === "ok" ? 0 : 1);'
      ;;
    nats)
      wait_for nats '$health = @file_get_contents(rtrim((string) getenv("IC_NATS_MONITOR_URL"), "/") . "/healthz"); $jsz = @file_get_contents(rtrim((string) getenv("IC_NATS_MONITOR_URL"), "/") . "/jsz"); exit(is_string($health) && str_contains(strtolower($health), "ok") && is_string($jsz) && $jsz !== "" ? 0 : 1);'
      ;;
    mailpit)
      wait_for mailpit '$api = @file_get_contents(rtrim((string) getenv("IC_MAILPIT_API_URL"), "/") . "/v1/info"); $socket = @fsockopen((string) getenv("IC_SMTP_HOST"), (int) getenv("IC_SMTP_PORT"), $errno, $error, 1); if (!is_resource($socket)) { exit(1); } $greeting = fgets($socket); fclose($socket); exit(is_string($api) && $api !== "" && is_string($greeting) && str_starts_with($greeting, "220") ? 0 : 1);'
      ;;
    elasticsearch)
      wait_for elasticsearch '$payload = @file_get_contents(rtrim((string) getenv("IC_ELASTICSEARCH_URL"), "/") . "/_cluster/health?wait_for_status=yellow&timeout=1s"); $data = is_string($payload) ? json_decode($payload, true) : null; exit(is_array($data) && in_array($data["status"] ?? null, ["yellow", "green"], true) ? 0 : 1);'
      ;;
    scylladb)
      wait_for scylladb '$context = stream_context_create(["http" => ["timeout" => 1, "ignore_errors" => true]]); $payload = @file_get_contents((string) getenv("IC_SCYLLADB_ENDPOINT"), false, $context); exit(is_string($payload) ? 0 : 1);'
      ;;
    *)
      echo "::error::Unknown integration service: ${service}"
      exit 1
      ;;
  esac

  case "${service}:${topology}" in
    mysql:replica|mariadb:replica|postgres:replica)
      upper="${service^^}"
      primary_dsn="IC_${upper}_PRIMARY_DSN"
      replica_dsn="IC_${upper}_REPLICA_DSN"
      user_name="IC_${upper}_USER"
      password_name="IC_${upper}_PASSWORD"
      replication_token="$(php -r 'echo bin2hex(random_bytes(8));')"
      php -r '$primary = new PDO((string) getenv($argv[1]), (string) getenv($argv[3]), (string) getenv($argv[4])); $primary->exec("CREATE TABLE IF NOT EXISTS phpforge_replication_probe (token VARCHAR(64) PRIMARY KEY)"); $statement = $primary->prepare("INSERT INTO phpforge_replication_probe (token) VALUES (?)"); $statement->execute([$argv[5]]);' "$primary_dsn" "$replica_dsn" "$user_name" "$password_name" "$replication_token"
      wait_for "${service}-replication" '$replica = new PDO((string) getenv($argv[2]), (string) getenv($argv[3]), (string) getenv($argv[4])); $query = $replica->prepare("SELECT token FROM phpforge_replication_probe WHERE token = ?"); $query->execute([$argv[5]]); exit($query->fetchColumn() === $argv[5] ? 0 : 1);' "$primary_dsn" "$replica_dsn" "$user_name" "$password_name" "$replication_token"
      ;;
    mssql:availability-group)
      replication_token="$(php -r 'echo bin2hex(random_bytes(8));')"
      wait_for mssql-availability-group '$replica = new PDO((string) getenv("IC_MSSQL_REPLICA_DSN"), (string) getenv("IC_MSSQL_USER"), (string) getenv("IC_MSSQL_PASSWORD")); exit($replica->query("SELECT 1") === false ? 1 : 0);'
      php -r '$primary = new PDO((string) getenv("IC_MSSQL_PRIMARY_DSN"), (string) getenv("IC_MSSQL_USER"), (string) getenv("IC_MSSQL_PASSWORD")); $check = $primary->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"); $check->execute(["dbo", "phpforge_replication_probe"]); if ((int) $check->fetchColumn() === 0) { $primary->exec("CREATE TABLE dbo.phpforge_replication_probe (token VARCHAR(64) PRIMARY KEY)"); } $statement = $primary->prepare("INSERT INTO dbo.phpforge_replication_probe (token) VALUES (?)"); $statement->execute([$argv[1]]);' "$replication_token"
      wait_for mssql-replication '$replica = new PDO((string) getenv("IC_MSSQL_REPLICA_DSN"), (string) getenv("IC_MSSQL_USER"), (string) getenv("IC_MSSQL_PASSWORD")); $query = $replica->prepare("SELECT token FROM dbo.phpforge_replication_probe WHERE token = ?"); $query->execute([$argv[1]]); exit($query->fetchColumn() === $argv[1] ? 0 : 1);' "$replication_token"
      ;;
    redis:replica|valkey:replica)
      upper="${service^^}"
      primary_host="IC_${upper}_HOST"
      primary_port="IC_${upper}_PORT"
      replica_host="IC_${upper}_REPLICA_HOST"
      replica_port="IC_${upper}_REPLICA_PORT"
      password_name="IC_${upper}_PASSWORD"
      replication_token="phpforge_probe_$(php -r 'echo bin2hex(random_bytes(8));')"
      php -r '$redis = new Redis(); $redis->connect((string) getenv($argv[1]), (int) getenv($argv[2])); $password = getenv($argv[5]); if (is_string($password) && $password !== "") { $redis->auth($password); } exit($redis->set($argv[6], $argv[6], 30) ? 0 : 1);' "$primary_host" "$primary_port" "$replica_host" "$replica_port" "$password_name" "$replication_token"
      wait_for "${service}-replication" '$redis = new Redis(); try { $redis->connect((string) getenv($argv[3]), (int) getenv($argv[4]), 0.5); $password = getenv($argv[5]); if (is_string($password) && $password !== "" && !$redis->auth($password)) { exit(1); } exit($redis->get($argv[6]) === $argv[6] ? 0 : 1); } catch (Throwable) { exit(1); }' "$primary_host" "$primary_port" "$replica_host" "$replica_port" "$password_name" "$replication_token"
      ;;
    rabbitmq:cluster)
      wait_for rabbitmq-cluster '$url = rtrim((string) getenv("IC_RABBITMQ_MANAGEMENT_URL"), "/") . "/api/nodes"; $auth = base64_encode((string) getenv("IC_SERVICE_USERNAME") . ":" . (string) getenv("IC_SERVICE_PASSWORD")); $context = stream_context_create(["http" => ["timeout" => 1, "header" => "Authorization: Basic {$auth}\r\n"]]); $payload = @file_get_contents($url, false, $context); $nodes = is_string($payload) ? json_decode($payload, true) : null; $running = is_array($nodes) ? array_filter($nodes, static fn($node): bool => is_array($node) && ($node["running"] ?? false) === true) : []; exit(count($running) >= 3 ? 0 : 1);'
      ;;
    nats:cluster)
      wait_for nats-cluster '$payload = @file_get_contents(rtrim((string) getenv("IC_NATS_MONITOR_URL"), "/") . "/routez"); $data = is_string($payload) ? json_decode($payload, true) : null; exit(is_array($data) && ($data["num_routes"] ?? 0) >= 2 ? 0 : 1);'
      ;;
    elasticsearch:cluster)
      wait_for elasticsearch-cluster '$payload = @file_get_contents(rtrim((string) getenv("IC_ELASTICSEARCH_URL"), "/") . "/_cluster/health?wait_for_nodes=2&timeout=1s"); $data = is_string($payload) ? json_decode($payload, true) : null; exit(is_array($data) && ($data["number_of_nodes"] ?? 0) >= 2 ? 0 : 1);'
      ;;
    scylladb:cluster)
      wait_for scylladb-cluster '$payload = @file_get_contents(rtrim((string) getenv("IC_SCYLLADB_ADMIN_URL"), "/") . "/gossiper/endpoint/live/"); $nodes = is_string($payload) ? json_decode($payload, true) : null; exit(is_array($nodes) && count($nodes) >= 3 ? 0 : 1);'
      ;;
  esac
done < <(jq -r '.[]' <<< "$services_json")
