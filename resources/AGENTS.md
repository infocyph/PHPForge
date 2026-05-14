# PHPForge Agent Notes

- For projects using `infocyph/phpforge`.
- Run `composer ic:doctor` and `composer ic:list-config` first.
- Keep changes scoped; do not edit `vendor/`.

## Core Flow

- `composer ic:process` (unless read-only task).
- `composer ic:tests:details`, fix issues, then re-run above command.
- Final check: `composer ic:tests` or `composer ic:release:guard`.
- If blocked, report the exact failing command + key error.

## Agent Behavior

- Execute the flow by default; do not ask for routine step confirmation.
- Ask only for destructive/risky actions, unclear product decisions or missing secrets.
- Run routine commands directly and report concise results.
- For reported clone groups, centralize repeated logic and update all affected call sites.

## Available Commands

- `composer ic:doctor` - show setup diagnostics (config resolution, plugin permissions, hooks/workflow checks).
- `composer ic:list-config` - list discovered config files and where each one is resolved from.
- `composer ic:publish-config [file...]` - copy bundled config file(s) into the project (`--all` and `--force` supported).
- `composer ic:init` - interactive project bootstrap for CaptainHook and CI/workflow wrappers.
- `composer ic:hooks` - install/update enabled CaptainHook hooks.
- `composer ic:clean` - remove known PHPForge output files and cache directories.
- `composer ic:version` - print PHPForge/PHP/runtime path metadata.
- `composer ic:phpstan:sarif input.json output.sarif` - convert PHPStan JSON to SARIF 2.1.0.

- `composer ic:tests` - run the full quality suite.
- `composer ic:tests:all` - alias of `ic:tests`.
- `composer ic:tests:parallel` - run syntax first, then bounded-parallel quality checks.
- `composer ic:tests:details` - run the detailed (non-parallel shortcut) quality checks.
- `composer ic:test:syntax` - PHP syntax scan.
- `composer ic:test:code` - run Pest tests.
- `composer ic:test:lint` - run Pint in check mode.
- `composer ic:test:sniff` - run PHPCS checks.
- `composer ic:test:duplicates` - duplicate code detection.
- `composer ic:test:probe` - run aggregate PHPProbe checks (syntax, duplicates, API, comments).
- `composer ic:test:api` - API snapshot checks via PHPProbe.
- `composer ic:test:comments` - comment policy checks via PHPProbe.
- `composer ic:test:architecture` - Deptrac architecture checks.
- `composer ic:test:static` - PHPStan analysis.
- `composer ic:test:security` - Psalm security analysis.
- `composer ic:test:refactor` - Rector dry-run checks.
- `composer ic:test:bench` - PHPBench aggregate benchmark run.

- `composer ic:process` - run normalize + Rector + Pint + PHPCBF fixers.
- `composer ic:process:all` - alias of `ic:process`.
- `composer ic:process:refactor` - Rector fix run.
- `composer ic:process:lint` - Pint fix run.
- `composer ic:process:sniff` - PHPCBF fix run.
- `composer ic:process:sniff:fix` - alias of `ic:process:sniff`.

- `composer ic:benchmark` - run PHPBench aggregate benchmarks.
- `composer ic:bench:run` - alias of `ic:benchmark`.
- `composer ic:bench:quick` - shorter PHPBench run.
- `composer ic:bench:chart` - generate PHPBench chart report.

- `composer ic:ci` - run CI suite using the bounded parallel runner.
- `composer ic:ci --prefer-lowest` - CI mode for prefer-lowest jobs (skips heavyweight static/security checks).
- `composer ic:release:audit` - run Composer audit guard.
- `composer ic:release:guard` - run Composer validation + audit + full quality suite.

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
