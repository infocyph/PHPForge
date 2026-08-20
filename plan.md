# PHPForge Finalized Upgrade Plan

## Objective

Evolve `infocyph/phpforge` from a reusable QA/refactoring/benchmark package into a lightweight **quality + integration-test environment orchestrator** while keeping it:

- framework-agnostic;
- lightweight;
- deterministic;
- fast by default;
- easy to consume as a dev dependency;
- explicit about real external service behavior;
- free from unnecessary abstractions;
- optimized around task-level parallelism rather than nested tool parallelism.

The upgrade should not turn PHPForge into a general-purpose infrastructure framework. Its responsibility is to provide reproducible test infrastructure and quality execution for PHP libraries/applications.

---

# 1. Core Execution Model

## 1.1 Task-level parallelism

PHPForge should use **one process per independent read-only quality tool**, with all eligible tools started concurrently.

Example aggregate run:

```text
PHPProbe check ──────────┐
Pest ────────────────────┤
Pint --test ─────────────┤
PHPCS ───────────────────┤
Deptrac ─────────────────┤── start together
PHPStan ─────────────────┤
Psalm ───────────────────┤
Rector --dry-run ────────┤
Composer normalize check ┘
```

Rules:

- each logical checker executes exactly once;
- PHPForge provides concurrency between tools;
- do not run duplicate copies of the same test/check;
- avoid nested parallelism inside tools by default;
- default aggregate concurrency should be the number of eligible tasks, bounded by a hard safety limit;
- allow `IC_TEST_CONCURRENCY` as an optional ceiling for constrained environments.

Recommended hard maximum:

```text
16
```

## 1.2 Remove nested Pest parallelism

Remove the default internal Pest parallel execution from aggregate PHPForge runs:

```text
--parallel
--processes
IC_PEST_PARALLEL
IC_PEST_PROCESSES
parallel-worker retry/fallback logic
```

Pest should run once as one PHPForge task.

Standalone Pest execution may remain directly available to consumers if they intentionally want Pest's own parallel mode outside PHPForge orchestration, but PHPForge should not enable it by default.

## 1.3 Psalm threading

Under aggregate PHPForge runs:

```text
Psalm threads = 1
```

Remove `Psalm threads` from normal `ic:init`.

If standalone execution requires tuning later, an environment override may remain available, but aggregate CI should default to one Psalm process/thread.

## 1.4 PHPStan internal parallelism

Remove/disable bundled PHPStan internal process parallelism:

```yaml
parallel:
    maximumNumberOfProcesses: 2
```

PHPStan should be one PHPForge task.

PHPForge itself owns top-level process concurrency.

## 1.5 PHPProbe consolidation

For aggregate runs, use one PHPProbe process:

```bash
phpprobe check
```

instead of independently executing:

```text
syntax
duplicates
comments
```

Keep focused commands:

```text
composer ic:test:syntax
composer ic:test:duplicates
composer ic:test:comments
composer ic:test:probe
```

for targeted development and diagnostics.

## 1.6 Mutating processors must remain sequential

Do **not** run source-mutating tools concurrently.

These may modify overlapping files:

```text
Composer Normalize
Rector fix
Pint fix
PHPCBF
```

Therefore:

```text
composer ic:process
```

must remain sequential and deterministic.

Rule:

```text
Checks     => task-level parallel
Processors => sequential
```

---

# 2. Command Behavior

## 2.1 `composer ic:tests`

Make the normal complete test command task-level parallel by default.

It should run the complete read-only QA suite through `ParallelRunner`.

## 2.2 `composer ic:ci`

Use the same task scheduler as `ic:tests`, with CI-specific behavior.

Do not maintain a separate concurrency model.

## 2.3 `composer ic:tests:parallel`

Once task-level parallelism becomes the normal execution model:

- make `ic:tests:parallel` an alias of `ic:tests`; then
- deprecate/remove it in the next major if desired.

## 2.4 `composer ic:tests:details`

Keep as the sequential diagnostic mode.

Purpose:

- easier failure investigation;
- deterministic tool-by-tool output;
- debugging environmental issues;
- reproducing one failing step at a time.

## 2.5 `--prefer-lowest`

The prefer-lowest suite should use the same top-level scheduler.

Only omit checks that are intentionally unsupported or too heavy under the prefer-lowest compatibility job.

Do not fall back to an entirely different sequential architecture merely because the dependency mode changes.

---

# 3. Parallel Runner Improvements

## 3.1 Dynamic concurrency

Replace the current low fixed default concurrency with:

```text
default = number of eligible tasks
```

bounded by:

```text
1..16
```

Allow:

```text
IC_TEST_CONCURRENCY
PHPFORGE_PARALLEL
```

as explicit overrides/ceilings if retained.

Prefer one canonical variable going forward.

Recommended canonical name:

```text
IC_TEST_CONCURRENCY
```

## 3.2 Output buffering

Avoid retaining arbitrarily large stdout/stderr strings for every concurrently running task.

Use bounded temporary streams/files per process.

Behavior:

- PASS -> print concise task result;
- SKIP -> print concise task result + reason when useful;
- FAIL -> emit buffered diagnostic output;
- final summary -> deterministic task ordering.

This keeps aggregate memory use predictable.

## 3.3 Fail behavior

All started independent checks should be allowed to finish so the user gets the complete failure picture.

Do not stop the entire parallel suite on the first failed checker.

Preflight checks may still block later execution when continuing would be invalid.

---

# 4. CI Duplicate-Execution Cleanup

The current reusable workflow can execute PHPStan/Psalm in the normal CI suite and then execute them again in the analysis/SARIF path.

Eliminate this duplication.

Target:

```text
one PHPStan execution
    -> quality result
    -> JSON/SARIF report

one Psalm execution
    -> security result
    -> SARIF report
```

Do not execute an analyzer twice in the same PHP/runtime/dependency environment merely to obtain a different report format.

Compatibility runs on distinct PHP versions/dependency matrices are still valid separate executions.

---

# 5. Integration Service Architecture

## 5.1 Final supported services

### Relational databases

- MySQL
- MariaDB
- PostgreSQL
- Microsoft SQL Server
- SQLite

### Document/database services

- MongoDB
- ScyllaDB / Alternator

### Cache/key-value services

- Redis
- Valkey
- Memcached

### Messaging

- RabbitMQ
- NATS with JetStream

### Search

- Elasticsearch

### Email

- Mailpit

Do not add Kafka, Redpanda, Pulsar, ActiveMQ, OpenSearch, MinIO, etc. until an actual consuming project needs them.

---

# 6. Replace Service Boolean Explosion

Do not continue expanding:

```text
enable_redis_service
enable_valkey_service
enable_memcached_service
enable_postgres_service
enable_mysql_service
...
```

The same setting currently needs to remain synchronized across workflow YAML, init, doctor, readiness logic, documentation and tests.

Replace the per-service boolean surface with a service list.

Recommended public input:

```yaml
integration_services: '["mysql","postgres","redis","mailpit"]'
```

Add topology configuration separately:

```yaml
service_topologies: '{"mysql":"replica","mongodb":"replica-set"}'
```

Advantages:

- future services do not require another workflow boolean;
- replica/cluster modes stay extensible;
- init becomes much shorter;
- doctor validation becomes simpler;
- one canonical service catalog can drive all behavior.

---

# 7. Canonical Service Catalog

Introduce one canonical service definition source.

Prefer a simple data structure/file over one class per service.

Possible location:

```text
resources/services/catalog.php
```

or:

```text
resources/services/catalog.json
```

The catalog should define, where applicable:

```text
name
label
compose profile
required PHP extensions
default host
default port
environment variables
DSN/URL template
health probe
supported topologies
topology profile names
```

Avoid designs such as:

```text
ServiceInterface
MysqlService
MariaDbService
PostgresService
RedisService
...
```

unless later behavior genuinely requires independent runtime objects.

The service layer is mostly static orchestration metadata.

---

# 8. Docker Compose Integration Layer

Move optional external integration services to a PHPForge-controlled Compose setup.

Suggested structure:

```text
resources/services/
    compose.yml
    catalog.php
    mysql/
    mariadb/
    postgres/
    mongodb/
    mssql/
    rabbitmq/
    nats/
```

Compose profiles:

```text
mysql
mysql-replica
mariadb
mariadb-replica
postgres
postgres-replica
mssql
redis
valkey
memcached
mongodb
mongodb-replica
rabbitmq
nats
mailpit
elasticsearch
scylladb
```

Benefits:

- same service definitions locally and in GitHub Actions;
- easier multi-container topologies;
- simpler reusable workflow;
- easier cleanup;
- fewer duplicated environment definitions;
- services can evolve independently from workflow syntax.

Do not keep half of the integration system as GitHub `services:` and half as Compose long term.

Migrate the optional integration layer together.

SQLite remains outside Compose.

---

# 9. Database Support

## 9.1 MySQL

Keep first-class MySQL support.

Expose:

```text
IC_MYSQL_DSN
IC_MYSQL_USER
IC_MYSQL_PASSWORD
```

When replica mode is selected:

```text
IC_MYSQL_PRIMARY_DSN
IC_MYSQL_REPLICA_DSN
```

Keep:

```text
IC_MYSQL_DSN
```

pointing to primary for normal compatibility.

## 9.2 MariaDB

Add MariaDB as an independent service, not an alias of MySQL.

Reasons:

- version-specific SQL behavior;
- optimizer differences;
- JSON differences;
- generated-column/index behavior;
- locking/transaction differences;
- feature/version divergence.

It may use the same PHP PDO driver:

```text
pdo_mysql
```

but must run against a real MariaDB server.

Expose:

```text
IC_MARIADB_DSN
IC_MARIADB_USER
IC_MARIADB_PASSWORD
```

Replica mode:

```text
IC_MARIADB_PRIMARY_DSN
IC_MARIADB_REPLICA_DSN
```

## 9.3 PostgreSQL

Keep current support.

Expose:

```text
IC_POSTGRES_DSN
IC_POSTGRES_USER
IC_POSTGRES_PASSWORD
```

Replica mode:

```text
IC_POSTGRES_PRIMARY_DSN
IC_POSTGRES_REPLICA_DSN
```

## 9.4 Microsoft SQL Server

Add first-class MSSQL support.

Required PHP/runtime support:

```text
pdo_sqlsrv
Microsoft ODBC driver
```

Expose:

```text
IC_MSSQL_DSN
IC_MSSQL_USER
IC_MSSQL_PASSWORD
```

Use a SQL Server Linux container.

Do not provide fake SQL Server replication.

Initially support standalone MSSQL only.

A future advanced topology may add:

```text
mssql: availability-group
```

when DBLayer or another project genuinely needs Always On/AG integration tests.

This should be considered a heavier/self-hosted-runner feature if necessary.

## 9.5 SQLite

Add SQLite as a first-class non-container database target.

Required extension:

```text
pdo_sqlite
```

Expose both:

```text
IC_SQLITE_MEMORY_DSN=sqlite::memory:
IC_SQLITE_FILE_DSN=sqlite:/tmp/phpforge.sqlite
```

Use cases:

### In-memory

- extremely fast tests;
- SQL generation sanity checks;
- transaction behavior;
- generic DB interfaces.

### File-backed

- multiple connections;
- locking;
- persistence;
- file-specific SQLite behavior.

Document clearly:

> Passing SQLite tests does not establish compatibility with MySQL, MariaDB, PostgreSQL or Microsoft SQL Server.

---

# 10. Replica / Topology Support

Replica support must verify **real replication**.

Starting two independent servers is not sufficient.

For relational replicas:

1. start primary;
2. start replica;
3. configure replication;
4. wait until replication reports healthy;
5. create/write a unique sentinel value on primary;
6. poll replica;
7. mark topology ready only after the sentinel becomes readable from the replica.

Initial replica targets:

- MySQL
- MariaDB
- PostgreSQL

## 10.1 MongoDB replica set

MongoDB should support:

```text
standalone
replica-set
```

Replica-set testing is valuable for:

- transactions;
- sessions;
- read preferences;
- primary/secondary routing;
- failover-sensitive code.

Expose:

```text
IC_MONGODB_DSN
IC_MONGODB_REPLICA_SET
```

Default replica-set test topology can use primary + secondary.

Only add a third member when election/failover testing needs it.

## 10.2 SQL Server replica

Do not include SQL Server AG/replica in the initial implementation.

Keep the topology model extensible so it can be added later without redesigning public configuration.

---

# 11. MongoDB Readiness Improvement

Current readiness should be strengthened.

Do not treat an open TCP port as proof that MongoDB is usable.

Use an actual database command equivalent to:

```text
ping
```

and validate authentication when credentials are enabled.

For replica-set mode, readiness must additionally verify the replica-set state.

---

# 12. Redis / Valkey / Memcached

Keep all existing services.

Do not alias Valkey through Redis environment variables.

Maintain independent variables:

```text
IC_REDIS_HOST
IC_REDIS_PORT
IC_REDIS_PASSWORD

IC_VALKEY_HOST
IC_VALKEY_PORT
IC_VALKEY_PASSWORD

IC_MEMCACHED_HOST
IC_MEMCACHED_PORT
```

A consumer explicitly chooses which service it is testing.

---

# 13. RabbitMQ

Add a real RabbitMQ integration service.

Expose:

```text
IC_RABBITMQ_HOST
IC_RABBITMQ_PORT
IC_RABBITMQ_DSN
IC_RABBITMQ_MANAGEMENT_URL
```

Do not force `ext-amqp`.

The consuming project chooses its RabbitMQ client implementation.

Readiness should validate the RabbitMQ server itself using its own diagnostics/health facilities.

Potential test coverage:

- publish;
- consume;
- acknowledgement;
- reject/nack;
- queue declaration;
- exchange routing;
- retry/dead-letter behavior;
- durable messages;
- consumer recovery.

---

# 14. NATS + JetStream

Add NATS with JetStream enabled.

Core NATS alone is insufficient for testing durable messaging scenarios.

Expose:

```text
IC_NATS_URL
IC_NATS_MONITOR_URL
```

Initial mode:

```text
nats = JetStream-enabled standalone
```

Do not require a specific PHP client library.

Potential test coverage:

- publish/subscribe;
- request/reply;
- streams;
- durable consumers;
- acknowledgements;
- redelivery;
- persistence.

Cluster mode can be added later through the topology model if required.

---

# 15. Mailpit / Email Testing

Add Mailpit as PHPForge's standard SMTP integration-test backend.

Expose:

```text
IC_SMTP_HOST
IC_SMTP_PORT
IC_SMTP_DSN

IC_MAILPIT_URL
IC_MAILPIT_API_URL
```

Mail testing flow:

1. consuming application sends through its actual mailer;
2. SMTP points to Mailpit;
3. Mailpit receives the message;
4. integration test queries Mailpit API;
5. assertions inspect actual message output.

Tests should be able to validate:

- sender;
- To;
- CC/BCC where applicable;
- subject;
- headers;
- text body;
- HTML body;
- attachments;
- message count;
- message cleanup;
- malformed/failure scenarios when Mailpit supports simulation.

Do not add a Mailpit-specific PHP client dependency to PHPForge.

PHPForge provisions Mailpit; consumers choose their own HTTP client/testing helper.

Mailpit does not replace provider-specific integration tests for:

- SES;
- SendGrid;
- Mailgun;
- Gmail;
- Outlook;
- SPF/DKIM reputation;
- production deliverability.

---

# 16. Automatic Extension Resolution

Selected services should automatically contribute required PHP extensions.

Current behavior should not require the user to manually know which extension a selected service needs.

Service extension mapping:

```text
mysql      -> pdo_mysql
mariadb    -> pdo_mysql
postgres   -> pdo_pgsql
mssql      -> pdo_sqlsrv
sqlite     -> pdo_sqlite
redis      -> redis
valkey     -> redis
memcached  -> memcached
mongodb    -> mongodb
```

Services that may use multiple pure-PHP/native clients should not force an extension:

```text
rabbitmq
nats
mailpit
elasticsearch
scylladb
```

Final workflow extension set:

```text
composer-declared ext-*
+
extensions required by selected PHPForge services
```

Deduplicate and install the union.

---

# 17. `ic:init` Redesign

Normal initialization should ask only questions that define the project integration setup.

Remove low-value execution-tuning questions.

## 17.1 Remove from normal init

Remove:

```text
PHPStan memory limit
Psalm threads
Extra Composer flags
Shared service database name
Shared service username
Shared service password
Enable SARIF?
Enable SVG report?
```

These have deterministic defaults or belong in advanced/manual workflow configuration.

Also avoid asking for common configuration that PHPForge can determine automatically:

```text
PHP version matrix
PHP extensions
dependency matrix
```

unless the user explicitly enters an advanced configuration path.

## 17.2 Recommended normal init flow

Keep the wizard short.

Suggested normal flow:

```text
Install GitHub Actions workflow?
Install CaptainHook?
Select integration services
Select optional service topologies for selected services
```

Other outputs already have explicit CLI flags and do not need to clutter every interactive setup:

```text
--gitlab-ci
--bitbucket-ci
--forgejo-workflow
--community-templates
```

## 17.3 Service selection

Use one multi-select rather than one yes/no prompt per service.

Example choices:

```text
mysql
mariadb
postgres
mssql
sqlite
mongodb
redis
valkey
memcached
rabbitmq
nats
mailpit
elasticsearch
scylladb
```

Then conditionally offer topology choices only for selected services that support them.

Example:

```text
mysql     -> standalone | replica
mariadb   -> standalone | replica
postgres  -> standalone | replica
mongodb   -> standalone | replica-set
```

## 17.4 Deterministic defaults

Recommended built-in defaults:

```text
workflow ref        -> main
PHP versions        -> PHPForge-supported matrix
dependency matrix   -> prefer-lowest + prefer-stable
extensions          -> automatically resolved
Composer flags      -> none
PHPStan memory      -> 1G
Psalm threads       -> 1
SARIF                -> enabled
SVG report           -> enabled
service database     -> phpforge
service username     -> phpforge
service password     -> ephemeral deterministic CI value
```

Advanced users can edit workflow inputs directly or use explicit CLI options.

---

# 18. Runtime Version Source of Truth

Avoid network-based PHP lifecycle discovery during normal `ic:init`.

Do not make initialization depend on `endoflife.date` or another external API.

Introduce one versioned runtime manifest, for example:

```text
resources/runtime.php
```

or:

```text
resources/runtime.json
```

It should define the PHP versions supported by that PHPForge release.

Use it from:

- init;
- workflow matrix preparation;
- doctor;
- tests;
- generated documentation where practical.

This eliminates multiple sources of truth.

---

# 19. Doctor Enhancements

`composer ic:doctor` should validate the new integration model.

Checks:

- `integration_services` is valid JSON/list;
- every selected service is known;
- topology keys refer to selected services;
- topology value is supported by the service;
- required PHP extensions can be resolved;
- Docker/Compose availability when external services are selected;
- service definitions are internally consistent;
- no duplicate/conflicting ports or env mappings;
- generated workflow contract is current;
- workflow version/ref is detectable;
- runtime matrix is valid.

Doctor should not contain another manually maintained list of every individual service boolean.

It should consume the canonical service catalog.

---

# 20. Service Readiness Refactor

The current readiness script will become difficult to maintain if more hard-coded `if` blocks are added.

Refactor readiness around the service catalog.

The implementation may remain Bash/PHP-based, but service metadata should drive:

- whether a service is enabled;
- required extension checks;
- health probe;
- retry policy;
- topology readiness;
- environment naming.

Keep the implementation simple and deterministic.

Do not build a large runtime service-object framework.

---

# 21. Readiness Rules

Every enabled external service must pass a protocol-level health check.

Examples:

```text
Redis       -> AUTH + PING
Valkey      -> AUTH + PING
Memcached   -> real set/get or stats probe
MySQL       -> PDO connection + SELECT 1
MariaDB     -> PDO connection + SELECT 1
PostgreSQL  -> PDO connection + SELECT 1
MSSQL       -> PDO SQLSRV connection + SELECT 1
MongoDB     -> database ping command
RabbitMQ    -> broker diagnostics/health
NATS        -> server monitoring/connection probe
Mailpit     -> HTTP health/API + SMTP port
Elasticsearch -> cluster health
ScyllaDB    -> protocol/HTTP endpoint health
```

Avoid socket-only readiness when a cheap protocol-level operation is available.

---

# 22. Service Credentials

Do not ask users for disposable CI credentials during normal init.

Use safe deterministic development/CI defaults.

Example:

```text
database: phpforge
username: phpforge
password: phpforge
```

These values are test-only and should never be presented as production defaults.

Allow explicit workflow overrides for projects requiring custom values.

Never use these defaults in production documentation examples without clearly marking them as test credentials.

---

# 23. Engineering Configuration Drift

Align bundled PHPStan cognitive/dependency-tree settings with PHPForge's own engineering principles.

Current intended baseline:

```yaml
cognitive_complexity:
    class: 80
    function: 12
    dependency_tree: 120
```

Ensure bundled PHPStan config also uses:

```text
dependency_tree: 120
```

Do not maintain conflicting values between engineering documentation and enforcement.

---

# 24. CaptainHook Ownership

`ic:init` should own creation/publication of `captainhook.json`.

The Composer plugin should not silently create the configuration when the project did not choose it.

Target behavior:

```text
ic:init --captainhook
    -> create config
    -> install hooks

existing captainhook.json + composer install/update
    -> refresh/install hooks

no captainhook.json
    -> do nothing
```

This keeps setup opt-in and predictable.

---

# 25. Dependency Review

Perform a final direct-dependency review.

For each `require` package:

1. confirm PHPForge production source directly uses it;
2. move development-only tooling to `require-dev` where appropriate;
3. remove unused dependencies;
4. verify with Composer dependency inspection;
5. rerun complete PHPForge CI.

Do not remove a package solely because use is not immediately obvious; confirm first.

---

# 26. Smaller Cleanup Items

Include these in the same major cleanup where appropriate:

- remove accidental/typo command aliases such as `ic:int` if still present;
- return proper child exit codes from task runners rather than unnecessarily converting command failures into exceptions;
- consolidate duplicated publication/setup logic inside `InitCommand`;
- keep source-type count lean;
- do not create per-service classes unless justified;
- keep config and service catalog data-driven;
- preserve focused commands for developers;
- keep aggregate behavior deterministic.

---

# 27. Public Workflow Target

A consuming project's reusable workflow configuration should ultimately remain compact.

Example:

```yaml
with:
  integration_services: '["mysql","mariadb","postgres","redis","rabbitmq","nats","mailpit"]'
  service_topologies: '{"mysql":"replica","postgres":"replica"}'
```

Avoid a public surface such as:

```text
enable_mysql_service
enable_mysql_replica
enable_mariadb_service
enable_mariadb_replica
enable_postgres_service
enable_postgres_replica
enable_mssql_service
enable_mongodb_service
enable_mongodb_replica
enable_rabbitmq_service
enable_nats_service
enable_mailpit_service
...
```

---

# 28. Local Integration Commands

Add a small service lifecycle command surface if it can be implemented cleanly.

Recommended:

```text
composer ic:services:up
composer ic:services:down
composer ic:services:status
```

Optional:

```text
composer ic:services:reset
```

These commands should read the project's selected integration services/topologies.

Avoid introducing a large Docker management subsystem.

Responsibilities:

### `services:up`

- resolve selected profiles;
- start required Compose services;
- wait for readiness;
- print connection variables.

### `services:down`

- stop PHPForge integration services;
- remove temporary resources created for the test environment.

### `services:status`

- report selected service/topology state;
- report readiness;
- show connection endpoints without exposing production secrets.

### `services:reset`

Only add if there is a real need for deterministic database/broker state reset.

---

# 29. Environment Variable Naming

Keep environment naming predictable and service-specific.

## MySQL

```text
IC_MYSQL_DSN
IC_MYSQL_PRIMARY_DSN
IC_MYSQL_REPLICA_DSN
IC_MYSQL_USER
IC_MYSQL_PASSWORD
```

## MariaDB

```text
IC_MARIADB_DSN
IC_MARIADB_PRIMARY_DSN
IC_MARIADB_REPLICA_DSN
IC_MARIADB_USER
IC_MARIADB_PASSWORD
```

## PostgreSQL

```text
IC_POSTGRES_DSN
IC_POSTGRES_PRIMARY_DSN
IC_POSTGRES_REPLICA_DSN
IC_POSTGRES_USER
IC_POSTGRES_PASSWORD
```

## MSSQL

```text
IC_MSSQL_DSN
IC_MSSQL_USER
IC_MSSQL_PASSWORD
```

## SQLite

```text
IC_SQLITE_MEMORY_DSN
IC_SQLITE_FILE_DSN
```

## MongoDB

```text
IC_MONGODB_DSN
IC_MONGODB_REPLICA_SET
```

## Redis

```text
IC_REDIS_HOST
IC_REDIS_PORT
IC_REDIS_PASSWORD
```

## Valkey

```text
IC_VALKEY_HOST
IC_VALKEY_PORT
IC_VALKEY_PASSWORD
```

## Memcached

```text
IC_MEMCACHED_HOST
IC_MEMCACHED_PORT
```

## RabbitMQ

```text
IC_RABBITMQ_HOST
IC_RABBITMQ_PORT
IC_RABBITMQ_DSN
IC_RABBITMQ_MANAGEMENT_URL
```

## NATS

```text
IC_NATS_URL
IC_NATS_MONITOR_URL
```

## Mailpit

```text
IC_SMTP_HOST
IC_SMTP_PORT
IC_SMTP_DSN
IC_MAILPIT_URL
IC_MAILPIT_API_URL
```

## Elasticsearch

```text
IC_ELASTICSEARCH_HOST
IC_ELASTICSEARCH_PORT
IC_ELASTICSEARCH_URL
```

## ScyllaDB

Keep the existing explicit Alternator variables.

---

# 30. Tests Required for PHPForge Itself

## Parallel runner

Test:

- all eligible tasks start without unnecessary serial waiting;
- concurrency ceiling is respected;
- each task executes exactly once;
- failure from one task does not suppress results from already-running peers;
- deterministic final summary;
- exit code is non-zero if any required check fails;
- skipped tasks are reported correctly;
- no Pest internal parallel arguments are injected;
- Psalm aggregate execution uses one thread;
- PHPStan bundled config does not request internal workers.

## Service catalog

Test:

- every service has a unique name;
- profile names are unique/valid;
- required env variables are complete;
- extension mappings are valid;
- topology definitions reference real profiles;
- no unsupported topology can be selected.

## Init

Test:

- default init is concise;
- removed tuning prompts no longer appear;
- service multi-select serializes correctly;
- topology selection only appears when applicable;
- generated workflow values are valid;
- no service credentials are unnecessarily requested.

## Doctor

Test:

- unsupported service detection;
- malformed JSON/list input;
- topology for an unselected service;
- invalid topology;
- missing Docker/Compose;
- missing required extension;
- valid integration configuration reports healthy.

## Service readiness

Test every supported service's probe independently.

Replica tests must prove actual replicated data visibility.

---

# 31. CI Matrix Strategy

Do not start every heavy external service on every PHP/dependency matrix cell unless the consuming project explicitly requests it.

Optimize CI cost:

### Quality matrix

Run normal PHP/dependency compatibility checks.

### Integration services

Start only selected services.

### Replica topologies

Start only when explicitly selected.

### Heavy MSSQL/replica scenarios

Allow separate jobs if runner capacity requires it.

Avoid multiplying:

```text
PHP versions
x dependency modes
x every possible service
x every possible topology
```

The consumer selects the infrastructure that is relevant to the project.

---

# 32. Performance Expectations

The upgrade should improve wall-clock CI time without creating CPU/memory oversubscription.

Benchmark before/after:

- total `ic:ci` elapsed time;
- peak process count;
- peak memory;
- CPU utilization;
- individual task duration;
- aggregate failure diagnostics time;
- cold service startup;
- warm service readiness;
- replica initialization time.

Acceptance target:

- task-level parallel `ic:ci` should materially outperform current sequential/partially nested execution on normal multi-core runners;
- resource use must remain bounded;
- no duplicate test/analyzer execution;
- no correctness loss.

---

# 33. Documentation Changes

Update:

```text
README.md
resources/AGENTS.md
resources/engineering-principles.md
workflow documentation
ic:init documentation
service environment reference
replica/topology documentation
Mailpit integration example
```

Documentation should explicitly state:

### Quality model

```text
PHPForge parallelizes independent tools, not duplicate copies of the same checker.
```

### Mutation model

```text
Source-mutating processors remain sequential.
```

### SQLite

```text
SQLite is an additional compatibility target, not a substitute for production database engines.
```

### Mailpit

```text
Mailpit validates SMTP/email integration but does not replace provider-specific or real deliverability testing.
```

### Replication

```text
Replica mode verifies real replicated visibility before tests start.
```

---

# 34. Implementation Phases

## Phase 1 — Parallel execution correction

- remove Pest internal parallel behavior from aggregate runs;
- set Psalm aggregate threads to 1;
- remove bundled PHPStan internal parallel worker setting;
- consolidate PHPProbe aggregate execution;
- launch read-only tools concurrently;
- keep processors sequential;
- improve task output buffering;
- use dynamic bounded concurrency.

## Phase 2 — CI analyzer deduplication

- PHPStan executes once per environment;
- Psalm executes once per environment;
- same executions provide both gating and SARIF/report output;
- remove redundant analyzer passes.

## Phase 3 — Simplify init

- remove PHPStan memory prompt;
- remove Psalm threads prompt;
- remove normal credential prompts;
- remove normal SARIF/SVG prompts;
- stop asking low-value runtime tuning questions;
- add one service multi-select;
- add conditional topology selection;
- keep advanced options outside the default wizard.

## Phase 4 — Canonical runtime/service metadata

- add runtime-version manifest;
- add service catalog;
- make doctor/init/workflow consume the same metadata;
- remove duplicated service lists.

## Phase 5 — Compose migration

Migrate existing services:

- Redis;
- Valkey;
- Memcached;
- PostgreSQL;
- MySQL;
- MongoDB;
- Elasticsearch;
- ScyllaDB.

Ensure local + CI behavior remains equivalent.

## Phase 6 — New standalone services

Add:

- MariaDB;
- MSSQL;
- SQLite support;
- RabbitMQ;
- NATS + JetStream;
- Mailpit.

## Phase 7 — Replica/topology support

Add:

- MySQL replica;
- MariaDB replica;
- PostgreSQL replica;
- MongoDB replica-set.

Verify real replication before test execution.

## Phase 8 — Doctor/readiness improvements

- protocol-level probes;
- catalog-driven extension checks;
- Compose availability;
- topology validation;
- environment diagnostics.

## Phase 9 — Existing cleanup

- align dependency-tree threshold to 120;
- fix CaptainHook ownership;
- review direct dependencies;
- clean stale aliases/duplicated setup paths;
- verify command exit behavior.

## Phase 10 — Documentation + final validation

Run:

```text
composer ic:process
composer ic:tests:details
composer ic:tests
composer ic:ci
composer ic:release:guard
```

Also test representative service combinations and replica topologies.

---

# 35. Final Acceptance Criteria

The upgrade is complete only when all of the following are true.

## Quality execution

- every read-only quality tool executes once;
- independent tools can run concurrently;
- no default nested Pest parallelism;
- Psalm aggregate mode uses one thread;
- PHPStan aggregate mode does not internally spawn additional PHPForge-configured workers;
- mutating processors remain sequential;
- aggregate results remain deterministic.

## Service architecture

- selected services are data-driven;
- no new per-service boolean explosion;
- one canonical service catalog drives init, doctor and orchestration;
- local and GitHub CI use equivalent integration environments where practical.

## Databases

- MySQL supported;
- MariaDB supported separately;
- PostgreSQL supported;
- MSSQL supported;
- SQLite memory + file modes supported;
- MongoDB supported;
- real MySQL/MariaDB/PostgreSQL replica modes work;
- MongoDB replica set works.

## Messaging/cache/search

- Redis works;
- Valkey works independently;
- Memcached works;
- RabbitMQ works;
- NATS JetStream works;
- Elasticsearch works;
- ScyllaDB works.

## Email

- Mailpit starts automatically when selected;
- SMTP endpoint is exported;
- Mailpit API endpoint is exported;
- integration tests can inspect delivered messages.

## Init

Normal `ic:init` no longer asks:

```text
PHPStan memory limit
Psalm threads
service DB username/password/name
low-level analyzer tuning
```

The normal flow remains short and capability-oriented.

## Doctor

Doctor validates:

- runtime matrix;
- service list;
- topology;
- required extensions;
- Docker/Compose;
- generated workflow contract.

## Cleanup

- engineering-principle dependency-tree value matches PHPStan config;
- CaptainHook is not silently created by Composer plugin activation;
- redundant analyzer runs are removed;
- unused dependencies are reviewed.

---

# 36. Final Design Principle

PHPForge should remain responsible for three things:

## Quality orchestration

Run the complete PHP quality/security/refactoring check set efficiently and deterministically.

## Integration-test environment orchestration

Provision the real databases, caches, brokers, search engines and SMTP catcher required by a consuming project.

## Test-environment parity

Do not pretend one backend proves compatibility with another.

```text
MySQL      -> real MySQL
MariaDB    -> real MariaDB
PostgreSQL -> real PostgreSQL
MSSQL      -> real SQL Server
SQLite     -> real SQLite
MongoDB    -> real MongoDB
RabbitMQ   -> real RabbitMQ
NATS       -> real NATS
Mail       -> real SMTP into Mailpit
```

PHPForge should make those environments easy to enable, easy to test and cheap to ignore when a project does not need them.
