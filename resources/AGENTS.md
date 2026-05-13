# PHPForge Agent Notes

- For projects using `infocyph/phpforge`.
- Run `composer ic:doctor` and `composer ic:list-config` first.
- Keep changes scoped; do not edit `vendor/`.

## Core Flow

- `composer ic:process` (unless read-only task).
- `composer ic:tests:details`, fix issues, then re-run.
- Final check: `composer ic:tests` or `composer ic:release:guard`.
- If blocked, report the exact failing command + key error.

## Agent Behavior

- Execute the flow by default; do not ask for routine step confirmation.
- Ask only for destructive/risky actions, unclear product decisions, or missing secrets.
- Run routine commands directly and report concise results.

## Key Commands

- `composer ic:process`
- `composer ic:tests:details`
- `composer ic:tests`
- `composer ic:tests:parallel`
- `composer ic:ci` / `composer ic:ci --prefer-lowest`
- `composer ic:release:guard`
- `composer ic:init`, `composer ic:hooks`

## CI Notes

- Config precedence: project root -> `vendor/infocyph/phpforge/resources` -> source `resources/` (only in `infocyph/phpforge` repo).
- Pest parallel is on by default for `ic:tests`/`ic:ci`.
- Use `IC_PEST_PARALLEL=0` to disable Pest parallel in unstable CI.
- Optional tuning: `IC_PEST_PROCESSES`, `IC_PSALM_THREADS`, `IC_PHPSTAN_MEMORY_LIMIT`.
- Reusable workflow optional service inputs:
  - `enable_redis_service`, `enable_valkey_service`, `enable_memcached_service`
  - `enable_postgres_service`, `enable_mysql_service`, `enable_scylladb_service`
  - `enable_elasticsearch_service`, `enable_mongodb_service`
- Reusable workflow shared credentials: `service_db_name`, `service_db_user`, `service_db_password`.
- Reusable workflow strict skip gate: set `fail_on_skipped_tests: true` to pass `--fail-on-skipped` to Pest in CI.
- Extension requirements by service:
  - Redis/Valkey require `redis` extension.
  - Memcached requires `memcached` extension.
  - PostgreSQL/MySQL require `pdo_pgsql`/`pdo_mysql`.
  - MongoDB requires `mongodb` extension.
- Service envs exported by workflow:
  - Redis: `IC_REDIS_HOST`, `IC_REDIS_PORT`, `IC_REDIS_PASSWORD`
  - Valkey: `IC_VALKEY_HOST`, `IC_VALKEY_PORT`, `IC_VALKEY_PASSWORD`
  - ScyllaDB Alternator: `IC_SCYLLADB_HOST`, `IC_SCYLLADB_PORT`, `IC_SCYLLADB_ENDPOINT`, `IC_SCYLLADB_REGION`, `IC_SCYLLADB_ACCESS_KEY_ID`, `IC_SCYLLADB_SECRET_ACCESS_KEY`
