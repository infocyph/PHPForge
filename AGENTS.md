# PHPForge Agent Notes

- Applies to PHP projects using `infocyph/phpforge`.
- First inspect with `composer ic:doctor` and `composer ic:list-config` (`--json` is available).
- Do not edit `vendor/` or commit cache/coverage/benchmark/generated output unless tracked.
- Keep edits scoped to the request and existing project architecture.
- Project config always overrides PHPForge defaults.

## Commands

- `composer ic:process` - fixes Rector, Pint, PHPCBF issues.
- `composer ic:tests:details` - detailed step-by-step errors.
- `composer ic:tests` - full quality suite.
- `composer ic:release:guard` - release gate.
- `composer ic:ci` / `composer ic:ci --prefer-lowest` - CI parity.
- `composer ic:init` / `composer ic:hooks` - project setup and hooks.

## Resolution Flow

- Run `composer ic:process` first unless the task is read-only.
- Run `composer ic:tests:details` and use its output for remaining fixes.
- Re-run `composer ic:tests:details` after edits.
- Finish with `composer ic:tests` or `composer ic:release:guard` when relevant.
- If blocked, report the failing command and key error.

## Config And CI

- Config priority: project `pest.xml`/`phpunit.xml`, `phpbench.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `pint.json`, `psalm.xml`, `rector.php`, `captainhook.json`; then PHPForge defaults.
- Syntax scan respects Git ignores, including `vendor`.
- Pre-commit runs `composer validate --strict`, `composer ic:release:audit`, `composer ic:tests`.
- `IC_HOOKS_STRICT=1` is default; use `IC_HOOKS_STRICT=0 composer install` only for best-effort hook install.
- Workflow: `infocyph/phpforge/.github/workflows/security-standards.yml@v1`; `@v1` is a literal Git ref, use `@v1.2.3` for exact pinning.
- Workflow inputs: `php_versions`, `dependency_versions`, `php_extensions`, `coverage`, `composer_flags`, `phpstan_memory_limit`, `psalm_threads`, `run_analysis`.
