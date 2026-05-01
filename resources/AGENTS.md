# PHPForge Agent Notes

- Applies to PHP projects using `infocyph/phpforge`.
- First inspect with `composer ic:doctor` and `composer ic:list-config` (`--json` is available).
- Do not edit `vendor/` or commit cache/coverage/benchmark/generated output unless tracked.
- Keep edits scoped to the request and existing project architecture.
- Project config always overrides PHPForge defaults.

## Commands

- `composer ic:process` - fixes Composer Normalize, Rector, Pint, PHPCBF issues.
- `composer ic:tests:details` - detailed step-by-step errors.
- `composer ic:tests` - full quality suite.
- `composer ic:tests:parallel` - syntax preflight plus bounded parallel quality checks.
- `composer ic:release:guard` - release gate.
- `composer ic:ci` / `composer ic:ci --prefer-lowest` - CI parity.
- `composer ic:init` / `composer ic:hooks` - project setup and hooks.
- `composer ic:publish-config phpforge.json` - customize native syntax/duplicate scan policy.

## Resolution Flow

- Run `composer ic:process` first unless the task is read-only.
- Run `composer ic:tests:details` and use its output for remaining fixes.
- Re-run `composer ic:tests:details` after edits.
- Finish with `composer ic:tests` or `composer ic:release:guard` when relevant.
- If blocked, report the failing command and key error.

## Config And CI

- Config priority: project `pest.xml`/`phpunit.xml`, `phpbench.json`, `phpforge.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `pint.json`, `psalm.xml`, `rector.php`, `captainhook.json`; then PHPForge defaults.
- `phpforge.json` controls native syntax and duplicate paths/excludes; empty `paths` means project-root discovery through Git-aware PHP file finding.
- Syntax and duplicate scans respect Git ignores plus configured `exclude`/`exclude_paths` entries.
- Native checker CLI paths override configured `paths`; CLI `--exclude` values are added to configured excludes.
- Pre-commit runs `composer validate --strict`, `composer normalize --dry-run`, `composer ic:release:audit`, `composer ic:tests`.
- `IC_HOOKS_STRICT=1` is default; use `IC_HOOKS_STRICT=0 composer install` only for best-effort hook install.
- Workflow: `infocyph/phpforge/.github/workflows/security-standards.yml@main`.
- Workflow inputs: `php_versions`, `dependency_versions`, `php_extensions`, `coverage`, `composer_flags`, `phpstan_memory_limit`, `psalm_threads`, `run_analysis`, `run_svg_report`, `artifact_retention_days`.
