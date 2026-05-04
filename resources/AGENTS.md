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
