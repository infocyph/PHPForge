# PHPForge Agent Notes

- For projects using `infocyph/phpforge`.
- Run `composer ic:doctor`, `composer ic:list-config`, and `composer ic:active-config` first.
- Keep changes scoped; do not edit `vendor/`.

## Core Flow

- `composer ic:process` (unless read-only task).
- If above command fails somehow try: `composer ic:process:lint`, `composer ic:process:sniff:fix`, `composer ic:process:refactor`
- `composer ic:tests:details`, fix issues, then re-run above command.
- Final check: `composer ic:tests` or `composer ic:release:guard`.
- If blocked, report the exact failing command + key error.

## Agent Behavior

- Execute the flow by default; do not ask for routine step confirmation.
- Ask only for destructive/risky actions, unclear product decisions or missing secrets.
- Run routine commands directly and report concise results.
- When editing code, keep cognitive complexity within the active PHPStan limits; check with `composer ic:active-config phpstan.neon.dist --parameter=cognitive_complexity --json` when relevant.
- For reported clone groups, centralize repeated logic and update all affected call sites.

## Available Commands

- `composer ic:doctor` - show setup diagnostics (config resolution, plugin permissions, hooks/workflow checks).
- `composer ic:list-config` - list discovered config files and where each one is resolved from.
- `composer ic:active-config [file...]` - show the active configs for supported tools, with optional file filtering and parameter lookup. Pass filenames directly like `phpcs.xml.dist`; if using a leading `--` token, use Composer's separator first: `composer ic:active-config -- --phpcs.xml.dist`.
- `composer ic:publish-config [file...]` - copy bundled config file(s) into the project (`--all` and `--force` supported).
- `composer ic:init` - concise project bootstrap for CaptainHook, the GitHub workflow, and selected integration services/topologies.
- `composer ic:community` - copy generic `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, issue forms/config, and PR template files.
- `composer ic:publish-community-templates` - alias of `composer ic:community`.
- `composer ic:hooks` - install/update enabled CaptainHook hooks.
- `composer ic:clean` - remove known PHPForge output files and cache directories.
- `composer ic:version` - print PHPForge/PHP/runtime path metadata.
- `composer ic:phpstan:sarif input.json output.sarif` - convert PHPStan JSON to SARIF 2.1.0.

- `composer ic:tests` - run the full quality suite.
- `composer ic:tests:all` - alias of `ic:tests`.
- `composer ic:tests:parallel` - alias of the bounded-parallel `ic:tests` suite.
- `composer ic:tests:details` - run the detailed (non-parallel shortcut) quality checks.
- `composer ic:test:syntax` - PHP syntax scan.
- `composer ic:test:code` - run Pest tests.
- `composer ic:test:lint` - run Pint in check mode.
- `composer ic:test:sniff` - run PHPCS checks.
- `composer ic:test:duplicates` - duplicate code detection.
- `composer ic:test:probe` - run aggregate PHPProbe checks (syntax, duplicates, comments).
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
- `composer ic:benchmark:validate result.json` - validate a workload-neutral representative result contract.
- `composer ic:benchmark:compare baseline.json candidate.json --stable-environment` - enforce the successful-RPM budget only for matching stable environments.
- `composer ic:soak:worker --duration=300 -- command [args...]` - monitor a long-running web or queue worker for early exit and RSS growth.

- `composer ic:ci` - run CI suite using the bounded parallel runner.
- `composer ic:ci --prefer-lowest` - CI mode for prefer-lowest jobs (skips heavyweight static/security checks).
- `composer ic:ci --without-analysis` - CI mode when a dedicated workflow job owns PHPStan/Psalm and SARIF.
- `composer ic:services:up` / `ic:services:down` / `ic:services:status` - manage catalog-selected local Compose services.
- `composer ic:release:audit` - run Composer audit guard.
- `composer ic:release:constraints` - reject non-stable runtime dependency constraints.
- `composer ic:release:guard` - run Composer validation + stable runtime constraints + audit + full quality suite.

## CI Notes

- Config precedence: project root -> `vendor/infocyph/phpforge/resources` -> source `resources/` (only in `infocyph/phpforge` repo).
- PHPForge parallelizes independent tools, not duplicate copies of the same checker. Aggregate Pest is one process, aggregate Psalm uses one thread, and PHPStan has no nested worker pool.
- Source-mutating processors remain sequential.
- `IC_TEST_CONCURRENCY` is the canonical bounded task concurrency setting (eligible task count by default, maximum 16); `PHPFORGE_PARALLEL` is a legacy fallback.
- Optional focused-command tuning: `IC_PSALM_THREADS`, `IC_PHPSTAN_MEMORY_LIMIT`.
- Reusable workflow services use `integration_services` (JSON list) and `service_topologies` (JSON object). The workflow resolves extensions automatically from Composer plus the canonical service catalog.
- Supported services: MySQL, MariaDB, PostgreSQL, MSSQL, SQLite, MongoDB, Redis, Valkey, Memcached, RabbitMQ, NATS JetStream, Mailpit, Elasticsearch and ScyllaDB.
- Replica modes are available for MySQL, MariaDB and PostgreSQL; MongoDB supports `replica-set`. Readiness proves replicated data visibility.
- SQLite is an additional compatibility target, not a substitute for production database engines.
- Mailpit validates SMTP/email integration but does not replace provider-specific or real deliverability testing.
- Reusable workflow strict skip gate: set `fail_on_skipped_tests: true` to pass `--fail-on-skipped` to Pest in CI.
- Reusable workflow clean install gate: `run_clean_install` defaults to `true` and checks production installation on the final configured PHP version.
- Representative benchmark inputs: `benchmark_composer_script`, `benchmark_result_file`, `benchmark_baseline_file`, `benchmark_max_regression_percent`, `benchmark_stable_environment`.
- Never enable the benchmark regression budget on a noisy environment. Both result files must also declare matching stable environment metadata.
- Extension requirements by service:
  - Redis/Valkey require `redis` extension.
  - Memcached requires `memcached` extension.
  - PostgreSQL requires `pdo_pgsql`; MySQL/MariaDB require `pdo_mysql`; MSSQL requires `pdo_sqlsrv`; SQLite requires `pdo_sqlite`.
  - MongoDB requires `mongodb` extension.
- Service envs exported by workflow:
  - Redis: `IC_REDIS_HOST`, `IC_REDIS_PORT`, `IC_REDIS_PASSWORD`
  - Valkey: `IC_VALKEY_HOST`, `IC_VALKEY_PORT`, `IC_VALKEY_PASSWORD`
  - ScyllaDB Alternator: `IC_SCYLLADB_HOST`, `IC_SCYLLADB_PORT`, `IC_SCYLLADB_ENDPOINT`, `IC_SCYLLADB_REGION`, `IC_SCYLLADB_ACCESS_KEY_ID`, `IC_SCYLLADB_SECRET_ACCESS_KEY`
