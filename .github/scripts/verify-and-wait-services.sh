#!/usr/bin/env bash
set -euo pipefail

is_enabled() {
  [ "${1:-false}" = "true" ]
}

wait_for() {
  local name="$1"
  local probe="$2"

  for i in {1..30}; do
    if php -r "$probe"; then
      echo "${name} ready"
      return 0
    fi

    sleep 1
  done

  echo "::error::${name} service not ready"
  return 1
}

missing=()

if is_enabled "${INPUT_ENABLE_REDIS_SERVICE:-false}" && ! php -m | grep -qi '^redis$'; then
  missing+=("redis")
fi

if is_enabled "${INPUT_ENABLE_MEMCACHED_SERVICE:-false}" && ! php -m | grep -qi '^memcached$'; then
  missing+=("memcached")
fi

if is_enabled "${INPUT_ENABLE_POSTGRES_SERVICE:-false}" && ! php -m | grep -qi '^pdo_pgsql$'; then
  missing+=("pdo_pgsql")
fi

if is_enabled "${INPUT_ENABLE_MYSQL_SERVICE:-false}" && ! php -m | grep -qi '^pdo_mysql$'; then
  missing+=("pdo_mysql")
fi

if is_enabled "${INPUT_ENABLE_MONGODB_SERVICE:-false}" && ! php -m | grep -qi '^mongodb$'; then
  missing+=("mongodb")
fi

if [ "${#missing[@]}" -gt 0 ]; then
  echo "::error::Missing required PHP extensions for enabled services: ${missing[*]}"
  exit 1
fi

if is_enabled "${INPUT_ENABLE_REDIS_SERVICE:-false}"; then
  wait_for redis '$r = new Redis(); try { if ($r->connect(getenv("IC_REDIS_HOST"), (int) getenv("IC_REDIS_PORT"), 0.5)) { $pass = getenv("IC_REDIS_PASSWORD"); if (is_string($pass) && $pass !== "") { if (!$r->auth($pass)) { exit(1); } } $pong = $r->ping(); if ($pong === true || stripos((string) $pong, "pong") !== false) { exit(0); } } } catch (Throwable) {} exit(1);'
fi

if is_enabled "${INPUT_ENABLE_MEMCACHED_SERVICE:-false}"; then
  wait_for memcached '$m = new Memcached(); $m->addServer(getenv("IC_MEMCACHED_HOST"), (int) getenv("IC_MEMCACHED_PORT")); $m->set("phpforge_ci_probe", "ok", 5); exit($m->getResultCode() === Memcached::RES_SUCCESS ? 0 : 1);'
fi

if is_enabled "${INPUT_ENABLE_POSTGRES_SERVICE:-false}"; then
  wait_for postgres '$dsn = getenv("IC_POSTGRES_DSN"); $user = getenv("IC_SERVICE_USERNAME"); $pass = getenv("IC_SERVICE_PASSWORD"); try { $pdo = new PDO($dsn, $user, $pass); $pdo->query("SELECT 1"); exit(0); } catch (Throwable) { exit(1); }'
fi

if is_enabled "${INPUT_ENABLE_MYSQL_SERVICE:-false}"; then
  wait_for mysql '$dsn = getenv("IC_MYSQL_DSN"); $user = getenv("IC_SERVICE_USERNAME"); $pass = getenv("IC_SERVICE_PASSWORD"); try { $pdo = new PDO($dsn, $user, $pass); $pdo->query("SELECT 1"); exit(0); } catch (Throwable) { exit(1); }'
fi

if is_enabled "${INPUT_ENABLE_DYNAMODB_SERVICE:-false}"; then
  wait_for dynamodb '$host = getenv("IC_DYNAMODB_HOST"); $port = (int) getenv("IC_DYNAMODB_PORT"); $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0.5); if (is_resource($socket)) { fclose($socket); exit(0); } exit(1);'
fi

if is_enabled "${INPUT_ENABLE_ELASTICSEARCH_SERVICE:-false}"; then
  wait_for elasticsearch '$url = getenv("IC_ELASTICSEARCH_URL") . "/_cluster/health?wait_for_status=yellow&timeout=1s"; $context = stream_context_create(["http" => ["timeout" => 1.0, "ignore_errors" => true]]); $payload = @file_get_contents($url, false, $context); if (is_string($payload) && $payload !== "") { exit(0); } exit(1);'
fi

if is_enabled "${INPUT_ENABLE_MONGODB_SERVICE:-false}"; then
  wait_for mongodb '$host = getenv("IC_MONGODB_HOST"); $port = (int) getenv("IC_MONGODB_PORT"); $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0.5); if (is_resource($socket)) { fclose($socket); exit(0); } exit(1);'
fi
