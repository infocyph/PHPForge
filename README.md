# PHPForge

Shared Composer-powered QA, refactoring, benchmark, release, hook, and CI tooling for Infocyph PHP projects.

PHPForge is installed as a dev dependency in PHP libraries and packages. It provides Composer commands under the `ic:*` namespace, ships default tool configuration, installs CaptainHook hooks, and exposes a reusable GitHub Actions workflow.

## What It Includes

PHPForge brings these tools through one package:

| Tool                     | Used For                                    |
| ------------------------ | ------------------------------------------- |
| CaptainHook              | Git hook installation and pre-commit checks |
| Pest                     | Test execution                              |
| Laravel Pint             | Code style checks and fixes                 |
| PHP_CodeSniffer / PHPCBF | Semantic sniffing and fixable sniff repairs |
| PHPStan                  | Static analysis and cognitive complexity    |
| Psalm                    | Security and taint analysis                 |
| Rector                   | Refactor checks and automated refactors     |
| PHPBench                 | Benchmarks                                  |
| Composer Normalize       | `composer.json` normalization               |
| Composer audit           | Release/security audit guard                |

## Install

Install in the consuming project:

```bash
composer require --dev infocyph/phpforge:dev-main
```

Composer may ask for plugin approval. If approval is needed, run:

```bash
composer config allow-plugins.infocyph/phpforge true
composer config allow-plugins.ergebnis/composer-normalize true
composer config allow-plugins.pestphp/pest-plugin true
composer install
```

Inspect the detected setup:

```bash
composer ic:doctor
```

JSON diagnostics are available for automation:

```bash
composer ic:doctor --json
```

## Agent Guide

For coding agents, CI agents, or external automation working inside a project that uses PHPForge, start with:

```text
vendor/infocyph/phpforge/resources/AGENTS.md
```

It summarizes project commands, verification steps, config priority rules, workflow inputs, hook behavior, and agent expectations. If an agent only auto-discovers root-level instruction files, copy or reference it from the project root as `AGENTS.md`.

## Quick Start

Common daily commands:

```bash
composer ic:tests
composer ic:process
composer ic:benchmark
composer ic:release:guard
```

Initialize optional project files:

```bash
composer ic:init
```

`ic:init` is interactive by default. It uses selector prompts for common choices and keeps a custom option for project-specific values:

```text
Install CaptainHook config?
Install GitHub Actions workflow wrapper?
PHPForge workflow ref
PHP version matrix
Dependency matrix
PHP extensions
Coverage driver
Extra Composer flags
PHPStan memory limit
Psalm threads
Enable SARIF code-scanning analysis?
Generate SVG security report artifacts?
```

Selector presets include:

| Prompt                | Built-in Choices                                                                                                |
| --------------------- | --------------------------------------------------------------------------------------------------------------- |
| PHPForge workflow ref | `main`, configured ref, or custom                                                                             |
| PHP version matrix    | `supported`, `current`, `stable`, or custom JSON. Presets resolve from Endoflife API (`https://endoflife.date/api/php.json`) with fallback to `["8.3","8.4","8.5"]`. |
| Dependency matrix     | `full` => `["prefer-lowest","prefer-stable"]`, `stable` => `["prefer-stable"]`, or custom JSON. Prompt shows resolved JSON beside each option. |
| PHP extensions        | `none` => `""`, `detected` (from project `composer.json` `ext-*` entries in `require`, `require-dev`, and `suggest`), `common`, `mysql`, `pgsql`, `mysql+pgsql`, or custom |
| Coverage driver       | `none`, `xdebug`, or `pcov`                                                                               |
| Extra Composer flags  | `none` => `""`, `with-all-dependencies` => `--with-all-dependencies`, `ignore-ext-redis` => `--ignore-platform-req=ext-redis`, or custom. Prompt explains each option effect. |
| PHPStan memory limit  | `1G`, `2G`, `4G`, or custom                                                                               |
| Psalm threads         | `1`, `2`, `4`, or custom                                                                                  |

`supported` includes non-EOL PHP minor cycles (>= `8.3`), `current` uses the latest two supported cycles, and `stable` uses the latest supported cycle.
PHP version, dependency matrix, PHP extensions, and Composer flags selectors show resolved values in the prompt and print the final resolved value after selection.

The generated files are:

```text
captainhook.json
.github/workflows/security-standards.yml
```

After `ic:init`, run:

```bash
composer ic:tests
```

If `captainhook.json` was installed, hooks auto-install on the next `composer install` or `composer update`.
Use `composer ic:hooks` only when you want to install/update hooks immediately.

Use targeted or non-interactive init commands when needed:

```bash
composer ic:init --captainhook
composer ic:init --workflow --workflow-ref=main
composer ic:init --no-interaction-defaults
composer ic:init --force
```

## Command Reference

### Test Commands

| Command                       | Purpose                                                                                                                                   |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `composer ic:tests`         | Full project quality suite: syntax, Pest parallel tests, Pint check, PHPCS summary, PHPStan, Psalm security analysis, and Rector dry run. |
| `composer ic:tests:all`     | Alias of `ic:tests`.                                                                                                                    |
| `composer ic:tests:details` | Runs detailed checks without the parallel Pest shortcut.                                                                                  |
| `composer ic:test:syntax`   | Checks project PHP files while respecting `.gitignore`, `.git/info/exclude`, and global Git ignore rules.                             |
| `composer ic:test:code`     | Runs Pest.                                                                                                                                |
| `composer ic:test:lint`     | Runs Pint in check mode.                                                                                                                  |
| `composer ic:test:sniff`    | Runs PHPCS with a full report.                                                                                                            |
| `composer ic:test:static`   | Runs PHPStan.                                                                                                                             |
| `composer ic:test:security` | Runs Psalm security analysis.                                                                                                             |
| `composer ic:test:refactor` | Runs Rector in dry-run mode.                                                                                                              |
| `composer ic:test:bench`    | Runs PHPBench aggregate benchmarks.                                                                                                       |

### CI Commands

| Command                            | Purpose                                                                      |
| ---------------------------------- | ---------------------------------------------------------------------------- |
| `composer ic:ci`                 | Runs syntax, Pest, Pint, PHPCS, Rector, PHPStan, and Psalm.                  |
| `composer ic:ci --prefer-lowest` | Runs the CI set without PHPStan and Psalm for prefer-lowest dependency jobs. |

### Process Commands

| Command                           | Purpose                                                   |
| --------------------------------- | --------------------------------------------------------- |
| `composer ic:process`             | Runs Composer Normalize, Rector, Pint, and PHPCBF fixes. |
| `composer ic:process:all`         | Alias of `ic:process`.                                    |
| `composer ic:process:refactor`    | Runs Rector fixes.                                        |
| `composer ic:process:lint`        | Runs Pint fixes.                                          |
| `composer ic:process:sniff`       | Runs PHPCBF fixes.                                        |
| `composer ic:process:sniff:fix`   | Alias of `ic:process:sniff`.                              |

### Benchmark Commands

| Command                     | Purpose                             |
| --------------------------- | ----------------------------------- |
| `composer ic:benchmark`   | Runs PHPBench aggregate benchmarks. |
| `composer ic:bench:run`   | Alias of `ic:benchmark`.          |
| `composer ic:bench:quick` | Runs a shorter PHPBench pass.       |
| `composer ic:bench:chart` | Runs PHPBench chart report.         |

### Release Commands

| Command                       | Purpose                                                                 |
| ----------------------------- | ----------------------------------------------------------------------- |
| `composer ic:release:audit` | Runs Composer audit. Security advisories fail; abandoned packages warn. |
| `composer ic:release:guard` | Runs Composer validation, audit, and the full test suite.               |

### Config And Utility Commands

| Command                                               | Purpose                                                                   |
| ----------------------------------------------------- | ------------------------------------------------------------------------- |
| `composer ic:init`                                  | Interactively sets up CaptainHook and the workflow wrapper.               |
| `composer ic:init --captainhook`                    | Copies only `captainhook.json`.                                         |
| `composer ic:init --workflow --workflow-ref=main`   | Copies only the workflow wrapper and points it at the given PHPForge ref. |
| `composer ic:init --no-interaction-defaults`        | Copies default init files without prompting.                              |
| `composer ic:init --force`                          | Overwrites existing copied files.                                         |
| `composer ic:hooks`                                 | Installs enabled CaptainHook hooks.                                       |
| `composer ic:doctor`                                | Shows detected configs, vendor-dir, plugin permissions, hook status, and workflow wrapper validation warnings. |
| `composer ic:doctor --json`                         | Outputs doctor diagnostics as JSON, including workflow wrapper validation details. |
| `composer ic:list-config`                           | Lists config files and their resolution source.                           |
| `composer ic:list-config --json`                    | Outputs config resolution as JSON.                                        |
| `composer ic:publish-config [file...]`              | Copies selected bundled config files into the project.                    |
| `composer ic:publish-config --all`                  | Copies every bundled config file into the project.                        |
| `composer ic:publish-config --all --force`          | Overwrites all project config files with bundled defaults.                |
| `composer ic:clean`                                 | Removes known PHPForge output files and cache directories.                |
| `composer ic:version`                               | Shows PHPForge, PHP, PHP binary, and vendor-dir information.              |
| `composer ic:phpstan:sarif input.json output.sarif` | Converts PHPStan JSON output to SARIF 2.1.0.                              |

## Configuration

Project config files always have priority over PHPForge bundled defaults.
PHPForge keeps its own active config files at the package root so the package can test itself with the same defaults it ships; `resources/` is reserved for distributable templates that should not affect editor or agent auto-discovery.

| Tool           | Lookup Order                                                  |
| -------------- | ------------------------------------------------------------- |
| Pest / PHPUnit | `pest.xml`, then `phpunit.xml`, then `pest.xml.dist`, then `phpunit.xml.dist`, then bundled `pest.xml` |
| PHPBench       | `phpbench.json`, then bundled `phpbench.json`             |
| PHPCS / PHPCBF | `phpcs.xml.dist`, then bundled `phpcs.xml.dist`           |
| PHPStan        | `phpstan.neon.dist`, then bundled `phpstan.neon.dist`     |
| Pint           | `pint.json`, then bundled `pint.json`                     |
| Psalm          | `psalm.xml`, then bundled `psalm.xml`                     |
| Rector         | `rector.php`, then bundled `rector.php`                   |
| CaptainHook    | `captainhook.json`, then bundled `captainhook.json`       |

Check active config sources:

```bash
composer ic:list-config
composer ic:list-config --json
```

Publish config only when a project needs custom rules:

```bash
composer ic:publish-config pint.json phpstan.neon.dist
composer ic:publish-config --all
```

Use `--force` to overwrite existing files:

```bash
composer ic:publish-config psalm.xml --force
```

## Environment Variables

| Variable                    | Default | Purpose                                                                                                  |
| --------------------------- | ------- | -------------------------------------------------------------------------------------------------------- |
| `IC_PEST_PROCESSES`       | `10`  | Controls Pest parallel processes for `ic:tests`.                                                       |
| `IC_PHPSTAN_MEMORY_LIMIT` | `1G`  | Controls PHPStan memory limit.                                                                           |
| `IC_PSALM_THREADS`        | `1`   | Controls Psalm thread count.                                                                             |
| `IC_HOOKS_STRICT`         | `1`   | Fails Composer when automatic CaptainHook install fails. Set to `0` for best-effort hook installation. |

Example:

```bash
IC_PEST_PROCESSES=4 composer ic:tests
IC_PHPSTAN_MEMORY_LIMIT=2G composer ic:test:static
IC_HOOKS_STRICT=0 composer install
```

## Git Hooks

Install the bundled CaptainHook configuration:

```bash
composer ic:init --captainhook
composer ic:hooks
```

The bundled pre-commit hook runs:

```bash
composer validate --strict
composer ic:release:audit
composer ic:tests
```

This package also has a root `post-autoload-dump` script:

```json
"post-autoload-dump": "captainhook install --only-enabled -nf"
```

That keeps hooks installed for this repository. Consuming projects get automatic hook installation from the PHPForge Composer plugin with project `captainhook.json` when present, otherwise with the bundled PHPForge `captainhook.json`.

## GitHub Actions

PHPForge publishes a reusable workflow:

```yaml
uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
```

Install a wrapper workflow into a consuming project:

```bash
composer ic:init
```

For automated setup, skip prompts and choose the reusable workflow ref:

```bash
composer ic:init --workflow --workflow-ref=main --no-interaction-defaults
```

Generated wrapper shape:

```yaml
name: "Security & Standards"

on:
  schedule:
    - cron: "0 0 * * 0"
  push:
    branches: [ "main", "master" ]
  pull_request:
    branches: [ "main", "master", "develop", "development" ]

jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    permissions:
      security-events: write
      actions: read
      contents: read
    with:
      php_versions: '["8.3","8.4","8.5"]'
      dependency_versions: '["prefer-lowest","prefer-stable"]'
      php_extensions: ""
      coverage: "none"
      composer_flags: ""
      phpstan_memory_limit: "1G"
      psalm_threads: "1"
      run_analysis: true
      run_svg_report: true
```

Workflow inputs:

| Input                    | Default                               | Purpose                                                                                      |
| ------------------------ | ------------------------------------- | -------------------------------------------------------------------------------------------- |
| `php_versions`         | `["8.3","8.4","8.5"]`               | PHP matrix as a JSON array string.                                                           |
| `dependency_versions`  | `["prefer-lowest","prefer-stable"]` | Composer dependency modes as a JSON array string.                                            |
| `php_extensions`       | `""`                                | Comma-separated PHP extensions passed to `shivammathur/setup-php`.                         |
| `coverage`             | `none`                              | Coverage driver passed to `shivammathur/setup-php`; use `xdebug`, `pcov`, or `none`. |
| `composer_flags`       | `""`                                | Extra flags appended to Composer install/update commands.                                    |
| `phpstan_memory_limit` | `1G`                                | PHPStan memory limit used by workflow analysis.                                              |
| `psalm_threads`        | `1`                                 | Psalm thread count used by workflow analysis.                                                |
| `run_analysis`         | `true`                              | Runs SARIF upload jobs for PHPStan and Psalm. Set to `false` for CI-only runs.             |
| `run_svg_report`       | `true`                              | Generates `security-report.svg` and `security-summary.json`, including benchmark status. |

### Workflow Input Details

`php_versions` must be a JSON array string because reusable workflow inputs are strings:

```yaml
with:
  php_versions: '["8.3","8.4","8.5"]'
```

Use a smaller matrix for faster daily CI, or the full supported range for release confidence.

`dependency_versions` controls Composer update mode:

```yaml
with:
  dependency_versions: '["prefer-stable"]'
```

For release confidence, keep both modes:

```yaml
with:
  dependency_versions: '["prefer-lowest","prefer-stable"]'
```

When the matrix entry is `prefer-lowest`, PHPForge runs `composer ic:ci --prefer-lowest`, skipping heavyweight PHPStan and Psalm checks for that entry.

`php_extensions` is passed to `shivammathur/setup-php`:

```yaml
with:
  php_extensions: "mbstring, intl, bcmath, pdo_mysql, pdo_pgsql"
```

Leave it empty when no extra extensions are needed:

```yaml
with:
  php_extensions: ""
```

`coverage` controls the setup-php coverage driver:

```yaml
with:
  coverage: "none"
```

Common values:

```yaml
coverage: "none"
coverage: "xdebug"
coverage: "pcov"
```

`composer_flags` appends extra flags to Composer install/update:

```yaml
with:
  composer_flags: "--ignore-platform-req=ext-redis"
```

Multiple flags can be passed as one string:

```yaml
with:
  composer_flags: "--ignore-platform-req=ext-redis --with-all-dependencies"
```

`phpstan_memory_limit` controls PHPStan memory in both quality gates and SARIF generation:

```yaml
with:
  phpstan_memory_limit: "2G"
```

`psalm_threads` controls Psalm parallelism:

```yaml
with:
  psalm_threads: "2"
```

`run_analysis` controls the SARIF upload job:

```yaml
with:
  run_analysis: false
```

Set it to `false` when the repository does not use GitHub code scanning, does not grant `security-events: write`, or wants CI-only runs.

`run_svg_report` controls the SVG reporting artifact job:

```yaml
with:
  run_svg_report: true
```

When enabled, the workflow uploads two artifacts:

- `security-report-svg` (contains `security-report.svg`)
- `security-summary-json` (contains `security-summary.json`)

`security-summary.json` includes benchmark metadata from `composer ic:bench:quick`:

- `benchmark_result`
- `benchmark_php_version`

### Workflow Examples

Fast CI for active development:

```yaml
jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    with:
      php_versions: '["8.4","8.5"]'
      dependency_versions: '["prefer-stable"]'
      run_analysis: false
      run_svg_report: true
```

Release confidence matrix:

```yaml
jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    permissions:
      security-events: write
      actions: read
      contents: read
    with:
      php_versions: '["8.3","8.4","8.5"]'
      dependency_versions: '["prefer-lowest","prefer-stable"]'
      run_analysis: true
```

Project with extensions and no SARIF upload:

```yaml
jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    with:
      php_versions: '["8.3","8.4"]'
      php_extensions: "mbstring, intl, pdo_mysql"
      composer_flags: "--ignore-platform-req=ext-redis"
      run_analysis: false
      run_svg_report: true
```

Project with extensions, coverage, and larger analysis limits:

```yaml
jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    permissions:
      security-events: write
      actions: read
      contents: read
    with:
      php_versions: '["8.3","8.4"]'
      dependency_versions: '["prefer-stable"]'
      php_extensions: "mbstring, intl, bcmath, pdo_mysql"
      coverage: "xdebug"
      composer_flags: "--ignore-platform-req=ext-redis"
      phpstan_memory_limit: "2G"
      psalm_threads: "2"
      run_analysis: true
```

For code scanning, project-local `phpstan.neon.dist` and `psalm.xml` are used when present; otherwise the workflow falls back to PHPForge defaults. The selected PHPStan config is the source of truth for analysed paths; PHPForge does not append extra CLI paths for SARIF generation.

## Migration Guide

Replace individual QA dependencies with PHPForge.

Before:

```json
"require-dev": {
    "captainhook/captainhook": "^5.29.2",
    "ergebnis/composer-normalize": "^2.51",
    "laravel/pint": "^1.29",
    "pestphp/pest": "^4.6.3",
    "pestphp/pest-plugin-drift": "^4.1",
    "phpbench/phpbench": "^1.6.1",
    "phpstan/phpstan": "^2.1.50",
    "rector/rector": "^2.4.2",
    "squizlabs/php_codesniffer": "^4.0.1",
    "symfony/var-dumper": "^7.3 || ^8.0.8",
    "tomasvotruba/cognitive-complexity": "^1.1",
    "vimeo/psalm": "^6.16.1"
}
```

After:

```json
"require-dev": {
    "infocyph/phpforge": "^1.0"
}
```

Remove old local QA scripts such as:

```text
test:*
process:*
bench:*
tests
process
benchmark
release:audit
release:guard
post-autoload-dump
```

Replace commands:

| Old command                                     | New command                       |
| ----------------------------------------------- | --------------------------------- |
| `composer tests` / `composer test:all`      | `composer ic:tests`             |
| `composer test:details`                       | `composer ic:tests:details`     |
| `composer test:syntax`                        | `composer ic:test:syntax`       |
| `composer test:code`                          | `composer ic:test:code`         |
| `composer test:lint`                          | `composer ic:test:lint`         |
| `composer test:sniff`                         | `composer ic:test:sniff`        |
| `composer test:static`                        | `composer ic:test:static`       |
| `composer test:security`                      | `composer ic:test:security`     |
| `composer test:refactor`                      | `composer ic:test:refactor`     |
| `composer process` / `composer process:all` | `composer ic:process`           |
| `composer process:lint`                       | `composer ic:process:lint`      |
| `composer process:sniff:fix`                  | `composer ic:process:sniff:fix` |
| `composer process:refactor`                   | `composer ic:process:refactor`  |
| `composer benchmark` / `composer bench:run` | `composer ic:benchmark`         |
| `composer bench:quick`                        | `composer ic:bench:quick`       |
| `composer bench:chart`                        | `composer ic:bench:chart`       |
| `composer release:audit`                      | `composer ic:release:audit`     |
| `composer release:guard`                      | `composer ic:release:guard`     |

Old helper scripts are no longer needed:

```text
.github/scripts/syntax.php
.github/scripts/composer-audit-guard.php
.github/scripts/phpstan-sarif.php
```

PHPForge provides those through:

```bash
composer ic:test:syntax
composer ic:release:audit
composer ic:phpstan:sarif phpstan-results.json phpstan-results.sarif
```

## Troubleshooting

### `There are no commands defined in the "ic" namespace`

The plugin is not active. Enable plugin permissions and reinstall:

```bash
composer config allow-plugins.infocyph/phpforge true
composer install
```

### CaptainHook install fails during Composer install

By default hook installation is strict. To make it best-effort:

```bash
IC_HOOKS_STRICT=0 composer install
```

Then inspect manually:

```bash
composer ic:doctor
composer ic:hooks
```

### GitHub code scanning upload fails

Set `run_analysis: false` in the workflow wrapper if the repository does not have SARIF upload permission:

```yaml
with:
  run_analysis: false
```

### SVG report artifact is missing

Ensure `run_svg_report: true` is present in the workflow wrapper:

```yaml
with:
  run_svg_report: true
```

Then open the workflow run and download `security-report-svg` and `security-summary-json`.

### A bundled rule is too strict

Publish the relevant config and edit it in the project:

```bash
composer ic:publish-config phpstan.neon.dist
composer ic:publish-config psalm.xml
```

Project config files always take priority over bundled defaults.
