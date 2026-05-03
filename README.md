# PHPForge

Shared Composer-powered QA, refactoring, benchmark, release, hook and CI tooling for Infocyph PHP projects.

PHPForge is installed as a dev dependency in PHP libraries and packages. It provides Composer commands under the `ic:*` namespace, ships default tool configuration, installs CaptainHook hooks, exposes a reusable GitHub Actions workflow and includes starter templates for GitLab CI, Bitbucket Pipelines and Forgejo Actions.

## What It Includes

PHPForge brings these tools through one package:

| Tool                        | Used For                                            |
| --------------------------- | --------------------------------------------------- |
| CaptainHook                 | Git hook installation and pre-commit checks         |
| Pest                        | Test execution                                      |
| Laravel Pint                | Code style checks and fixes                         |
| PHP_CodeSniffer / PHPCBF    | Semantic sniffing and fixable sniff repairs         |
| PHPProbe                    | Git-aware PHP syntax validation and duplicate detection |
| Deptrac                     | Architecture boundary checks                        |
| PHPStan                     | Static analysis and cognitive complexity            |
| Psalm                       | Security and taint analysis                         |
| Rector                      | Refactor checks and automated refactors             |
| PHPBench                    | Benchmarks                                          |
| Composer Normalize          | `composer.json` normalization                     |
| Composer audit              | Release/security audit guard                        |

## Install

Install in the consuming project (you will go through some series of approval, check and allow):

```bash
composer require --dev infocyph/phpforge:dev-main
```

If approval is needed (if not allowed in primary run or missed somehow), run:

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
Install CaptainHook pre-commit config (validate, audit, parallel CI)?
Install GitHub Actions workflow wrapper (parallel CI, SARIF, SVG report)?
PHPForge checker config preset
Install Deptrac architecture config (deptrac.yaml)?
Install GitLab CI pipeline (.gitlab-ci.yml)?
Install Bitbucket pipeline (bitbucket-pipelines.yml)?
Install Forgejo workflow (.forgejo/workflows/security-standards.yml)?
PHPForge workflow ref
PHP version matrix
Dependency matrix
PHP extensions
Coverage driver
Extra Composer flags
PHPStan memory limit
Psalm threads
Enable SARIF code-scanning analysis job?
Generate SVG security and quality report artifacts?
```

Selector presets include:

| Prompt                | Built-in Choices                                                                                                                                                                                   |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHPForge workflow ref | `main`, configured ref, or custom                                                                                                                                                                |
| PHP version matrix    | `supported`, `current`, `stable`, or custom JSON. Presets resolve live with fallback to `["8.2","8.3","8.4","8.5"]`.                                                                       |
| Dependency matrix     | `full` => `["prefer-lowest","prefer-stable"]`, `stable` => `["prefer-stable"]`, or custom JSON. Prompt shows resolved JSON beside each option.                                             |
| PHP extensions        | `none` => `""`, `detected` (from project `composer.json` `ext-*` entries in `require`, `require-dev`, and `suggest`), `common`, `mysql`, `pgsql`, `mysql+pgsql`, or custom |
| Coverage driver       | `none`, `xdebug`, or `pcov`                                                                                                                                                                  |
| Extra Composer flags  | `none` => `""`, `with-all-dependencies` => `--with-all-dependencies`, `ignore-ext-redis` => `--ignore-platform-req=ext-redis`, or custom. Prompt explains each option effect.          |
| PHPStan memory limit  | `1G`, `2G`, `4G`, or custom                                                                                                                                                                  |
| Psalm threads         | `1`, `2`, `4`, or custom                                                                                                                                                                     |
| PHPForge checker config preset | `phpstorm`, `standard`, or `strict`. Asked only when publishing `phpforge.json`.                                                                                             |

`supported` includes non-EOL PHP minor cycles (>= `8.2`), `current` uses the latest two supported cycles, and `stable` uses the latest supported cycle.
PHP version, dependency matrix, PHP extensions, and Composer flags selectors show resolved values in the prompt and print the final resolved value after selection.

Depending on your selections, `ic:init` can generate:

```text
captainhook.json
phpforge.json
deptrac.yaml
.github/workflows/security-standards.yml
.gitlab-ci.yml
bitbucket-pipelines.yml
.forgejo/workflows/security-standards.yml
```

Interactive init asks for a PHPForge checker config preset and publishes `phpforge.json` from that choice. It also asks whether to publish `deptrac.yaml`. Non-interactive default init still keeps bundled fallbacks unless `--phpforge` or `--deptrac` is passed. Use `composer ic:publish-config phpforge.json deptrac.yaml` when you only want to publish those files outside init.

After `ic:init`, run:

```bash
composer ic:ci
```

`composer ic:ci` is the same path used by the generated workflow and bundled pre-commit hook; it runs syntax first, then the remaining normal quality checks with bounded parallelism.

If `captainhook.json` was installed, hooks auto-install on the next `composer install` or `composer update`.
Use `composer ic:hooks` only when you want to install/update hooks immediately.

Use targeted or non-interactive init commands when needed:

```bash
composer ic:init --captainhook
composer ic:init --phpforge
composer ic:init --deptrac
composer ic:init --workflow --workflow-ref=main
composer ic:init --gitlab-ci
composer ic:init --bitbucket-ci
composer ic:init --forgejo-workflow
composer ic:init --no-interaction-defaults
composer ic:init --force
```

## Command Reference

### Test Commands

| Command                         | Purpose                                                                                                                                                        |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `composer ic:tests`           | Full project quality suite: syntax, Pest parallel tests, Pint check, PHPCS summary, duplicate detection, Deptrac, PHPStan, Psalm security analysis, and Rector dry run. |
| `composer ic:tests:all`       | Alias of `ic:tests`.                                                                                                                                         |
| `composer ic:tests:parallel`  | Runs syntax first, then executes the remaining quality checks with bounded parallelism and a buffered PASS/FAIL summary.                                       |
| `composer ic:tests:details`   | Runs detailed checks without the parallel Pest shortcut.                                                                                                       |
| `composer ic:test:syntax`     | Runs the PHP syntax checker using `phpforge.json`, Git ignores, and configured excludes.                                                                     |
| `composer ic:test:code`       | Runs Pest.                                                                                                                                                     |
| `composer ic:test:lint`       | Runs Pint in check mode.                                                                                                                                       |
| `composer ic:test:sniff`      | Runs PHPCS with a full report against the project root and bundled/project excludes.                                                                           |
| `composer ic:test:duplicates` | Runs duplicate detection using `phpforge.json`.                                                                                                              |
| `composer ic:test:architecture` | Runs Deptrac architecture checks using `deptrac.yaml`.                                                                                                    |
| `composer ic:test:static`     | Runs PHPStan.                                                                                                                                                  |
| `composer ic:test:security`   | Runs Psalm security analysis.                                                                                                                                  |
| `composer ic:test:refactor`   | Runs Rector in dry-run mode.                                                                                                                                   |
| `composer ic:test:bench`      | Runs PHPBench aggregate benchmarks.                                                                                                                            |

Syntax and duplicate settings live in `phpforge.json`, with the bundled default used when a project-local file is not present.
PHPForge delegates these checks to `vendor/bin/phpprobe`; the `phpforge syntax` and `phpforge duplicates` commands are thin compatibility gateways that pass the same config to PHPProbe.
Both checks are root-scoped by default because their bundled `paths` lists are empty; Git-aware PHP discovery is then filtered by the configured `exclude` lists.
Duplicate detection defaults are aligned with PhpStorm-style clone analysis: variable/literal normalization, fuzzy identifier/call anonymization, structural audit mode, near-miss matching, and a mid-sensitivity token window are enabled.
Use the lower-level binary for custom scans; CLI paths override configured paths, while CLI excludes are added to configured excludes:

```bash
php vendor/bin/phpprobe syntax --config=phpforge.json --exclude=storage
php vendor/bin/phpprobe duplicates --config=phpforge.json --min-lines=5 --min-tokens=70
php vendor/bin/phpprobe duplicates --config=phpforge.json --mode=audit --near-miss --json --exclude=tests
php vendor/bin/phpprobe duplicates --config=phpforge.json --write-baseline=.phpforge-duplicates-baseline.json
php vendor/bin/phpprobe duplicates --config=phpforge.json --baseline=.phpforge-duplicates-baseline.json
```

Useful checker options:

| Option                      | Applies To         | Purpose                                                                 |
| --------------------------- | ------------------ | ----------------------------------------------------------------------- |
| `--config=FILE`           | Syntax, duplicates | Reads checker settings from a custom `phpforge.json` file.            |
| `--exclude=PATH`          | Syntax, duplicates | Excludes one path; repeat it for multiple one-off exclusions.           |
| `--exact`                 | Duplicates         | Disables variable/literal normalization.                                |
| `--fuzzy`                 | Duplicates         | Also normalizes identifiers and calls for renamed-code scans.           |
| `--mode=audit`            | Duplicates         | Enables statement-window matching in addition to token matching.        |
| `--near-miss`             | Duplicates         | Enables bounded statement/shape similarity for edited clones.           |
| `--min-lines=N`           | Duplicates         | Sets the minimum duplicated line span.                                  |
| `--min-tokens=N`          | Duplicates         | Sets the token fingerprint window size.                                 |
| `--min-statements=N`      | Duplicates         | Sets the structural statement window size for audit matching.           |
| `--min-similarity=0.85`   | Duplicates         | Sets the near-miss similarity threshold.                                |
| `--baseline=FILE`         | Duplicates         | Suppresses clone groups already captured in a baseline.                 |
| `--write-baseline[=FILE]` | Duplicates         | Writes the current clone groups as the baseline and exits successfully. |
| `--json`                  | Duplicates         | Emits machine-readable JSON.                                            |

### CI Commands

| Command                            | Purpose                                                                                     |
| ---------------------------------- | ------------------------------------------------------------------------------------------- |
| `composer ic:ci`                 | Runs the normal CI suite through the same bounded parallel runner as `ic:tests:parallel`. |
| `composer ic:ci --prefer-lowest` | Runs the CI set without PHPStan and Psalm for prefer-lowest dependency jobs.                |

### Process Commands

| Command                           | Purpose                                                  |
| --------------------------------- | -------------------------------------------------------- |
| `composer ic:process`           | Runs Composer Normalize, Rector, Pint, and PHPCBF fixes. |
| `composer ic:process:all`       | Alias of `ic:process`.                                 |
| `composer ic:process:refactor`  | Runs Rector fixes.                                       |
| `composer ic:process:lint`      | Runs Pint fixes.                                         |
| `composer ic:process:sniff`     | Runs PHPCBF fixes.                                       |
| `composer ic:process:sniff:fix` | Alias of `ic:process:sniff`.                           |

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

| Command                                               | Purpose                                                                                                        |
| ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `composer ic:init`                                  | Interactively sets up CaptainHook pre-commit, workflow wrappers, and checker config publishing.               |
| `composer ic:init --captainhook`                    | Copies only the CaptainHook pre-commit config.                                                               |
| `composer ic:init --phpforge`                       | Publishes `phpforge.json` only when a project needs custom syntax or duplicate detection settings.            |
| `composer ic:init --phpforge --phpforge-preset=standard` | Publishes `phpforge.json` with a named preset: `phpstorm`, `standard`, or `strict`.                      |
| `composer ic:init --deptrac`                        | Copies only the Deptrac architecture config.                                                                  |
| `composer ic:init --workflow --workflow-ref=main`   | Copies only the GitHub Actions wrapper and points it at the given PHPForge ref.                               |
| `composer ic:init --gitlab-ci`                      | Copies `.gitlab-ci.yml` starter pipeline.                                                                    |
| `composer ic:init --bitbucket-ci`                   | Copies `bitbucket-pipelines.yml` starter pipeline.                                                           |
| `composer ic:init --forgejo-workflow`               | Copies `.forgejo/workflows/security-standards.yml` starter workflow.                                         |
| `composer ic:init --no-interaction-defaults`        | Copies default init files without prompting.                                                                   |
| `composer ic:init --force`                          | Overwrites existing copied files.                                                                              |
| `composer ic:hooks`                                 | Installs enabled CaptainHook hooks.                                                                            |
| `composer ic:doctor`                                | Shows detected configs, vendor-dir, plugin permissions, hook status, and workflow wrapper validation warnings. |
| `composer ic:doctor --json`                         | Outputs doctor diagnostics as JSON, including workflow wrapper validation details.                             |
| `composer ic:list-config`                           | Lists config files and their resolution source.                                                                |
| `composer ic:list-config --json`                    | Outputs config resolution as JSON.                                                                             |
| `composer ic:publish-config [file...]`              | Copies selected bundled config files into the project.                                                         |
| `composer ic:publish-config --all`                  | Copies every bundled config file into the project.                                                             |
| `composer ic:publish-config --all --force`          | Overwrites all project config files with bundled defaults.                                                     |
| `composer ic:clean`                                 | Removes known PHPForge output files and cache directories.                                                     |
| `composer ic:version`                               | Shows PHPForge, PHP, PHP binary, and vendor-dir information.                                                   |
| `composer ic:phpstan:sarif input.json output.sarif` | Converts PHPStan JSON output to SARIF 2.1.0.                                                                   |

## Configuration

Project config files always have priority over PHPForge bundled defaults.
For every bundled PHPForge config in `resources/`, lookup is:

1. Project root config, for example `pint.json` or `phpstan.neon.dist`.
2. Installed package config under `vendor/infocyph/phpforge/resources`.
3. PHPForge source-tree `resources/` only when the current project itself is `infocyph/phpforge`.

If none of those exists outside the PHPForge source project, PHPForge fails instead of silently inventing a config path.

| Tool                   | Lookup Order                                                                                                     |
| ---------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Pest / PHPUnit         | `pest.xml`, then `phpunit.xml`, then `pest.xml.dist`, then `phpunit.xml.dist`, then bundled `pest.xml` |
| PHPBench               | `phpbench.json`, then bundled `phpbench.json`                                                                |
| PHPProbe checker tasks | `phpforge.json`, then bundled `phpforge.json`                                                                |
| Deptrac                | `deptrac.yaml`, then bundled `deptrac.yaml`                                                                  |
| PHPCS / PHPCBF         | `phpcs.xml.dist`, then bundled `phpcs.xml.dist`                                                              |
| PHPStan                | `phpstan.neon.dist`, then bundled `phpstan.neon.dist`                                                        |
| Pint                   | `pint.json`, then bundled `pint.json`                                                                        |
| Psalm                  | `psalm.xml`, then bundled `psalm.xml`                                                                        |
| Rector                 | `rector.php`, then bundled `rector.php`                                                                      |
| CaptainHook            | `captainhook.json`, then bundled `captainhook.json`                                                          |

### PHPProbe Checker Config

`phpforge.json` configures PHPProbe syntax and duplicate-code checks.
Both sections use root-scoped discovery when `paths` is empty: PHPProbe asks Git for tracked/unignored PHP files, then falls back to recursively scanning the project root if Git is unavailable.
Use `exclude` to keep tests, generated files, caches, vendor packages, and other noisy paths out of the checker tasks.

Bundled default:

```json
{
  "syntax": {
    "paths": [],
    "exclude": [
      "tests",
      "vendor",
      "node_modules",
      ".git",
      ".idea",
      ".vscode",
      "coverage",
      ".phpunit.cache",
      ".psalm-cache",
      "build",
      "dist",
      "tmp",
      ".tmp",
      "storage",
      "bootstrap/cache",
      "var/cache"
    ]
  },
  "duplicates": {
    "paths": [],
    "exclude": [
      "tests",
      "vendor",
      "node_modules",
      ".git",
      ".idea",
      ".vscode",
      "coverage",
      ".phpunit.cache",
      ".psalm-cache",
      "build",
      "dist",
      "tmp",
      ".tmp",
      "storage",
      "bootstrap/cache",
      "var/cache",
      "storage/framework/views"
    ],
    "mode": "audit",
    "normalize": true,
    "fuzzy": true,
    "near_miss": true,
    "min_lines": 5,
    "min_tokens": 90,
    "min_statements": 4,
    "min_similarity": 0.85,
    "baseline": "",
    "write_baseline": "",
    "json": false
  }
}
```

| Key                           | Purpose                                                                                                                                            |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `syntax.paths`              | PHP files/directories checked by `phpforge syntax`. Empty means Git-aware project-root discovery.                                                |
| `syntax.exclude`            | Paths removed from syntax discovery. The bundled config excludes tests, vendor, caches, build output, storage, and common IDE/tool directories.    |
| `duplicates.paths`          | PHP files/directories scanned by `phpforge duplicates`. Empty means Git-aware project-root discovery.                                            |
| `duplicates.exclude`        | Paths removed from duplicate detection. The bundled config excludes tests, vendor, caches, build output, storage, and generated framework views.   |
| `duplicates.mode`           | `audit` enables structural statement matching and near-miss matching; `gate` is a stricter deterministic CI mode.                              |
| `duplicates.normalize`      | Replaces noisy variable names and literals before matching. Set `false` for exact-style matching.                                                |
| `duplicates.fuzzy`          | Also normalizes identifiers/calls for renamed-code audits. More sensitive, potentially noisier.                                                    |
| `duplicates.near_miss`      | Finds edited clones through bounded statement/shape similarity. Mostly useful with audit mode.                                                     |
| `duplicates.min_lines`      | Minimum duplicated lines before a clone group can be reported.                                                                                     |
| `duplicates.min_tokens`     | Token fingerprint window size. The bundled `90` is PhpStorm-like middle sensitivity; higher values are quieter, lower values are more sensitive. |
| `duplicates.min_statements` | Statement-window size used by audit/structural matching.                                                                                           |
| `duplicates.min_similarity` | Near-miss threshold from `0.0` to `1.0`; `0.85` means 85 percent similar.                                                                    |
| `duplicates.baseline`       | Baseline file whose known clone fingerprints are suppressed.                                                                                       |
| `duplicates.write_baseline` | Writes current clone groups to the given baseline and exits successfully. Usually use CLI for this.                                                |
| `duplicates.json`           | Emits machine-readable JSON instead of human text. Best for automation/reporting.                                                                  |

`exclude_paths` is also accepted as an alias for `exclude` in either section.
Snake case, kebab case, and camel case are accepted for checker config keys, so `min_tokens`, `min-tokens`, and `minTokens` resolve to the same setting.
Explicit CLI paths override configured `paths`; configured and CLI excludes are combined.

PHPForge checker config presets are available when publishing `phpforge.json` through `ic:init --phpforge`:

| Preset      | Duplicate Policy                                                                 |
| ----------- | -------------------------------------------------------------------------------- |
| `phpstorm`  | PhpStorm-like default: audit mode, normalized/fuzzy tokens, near-miss matching, `min_tokens: 90`. |
| `standard`  | Balanced CI gate: deterministic gate mode, normalized/fuzzy tokens, no near-miss matching, `min_tokens: 100`. |
| `strict`    | More sensitive audit mode: near-miss matching enabled with lower size thresholds, `min_tokens: 70`. |

```bash
composer ic:init --phpforge
composer ic:init --phpforge --phpforge-preset=standard
```

### Deptrac Architecture Config

`deptrac.yaml` configures architecture dependency boundaries. The bundled default scans from the project root, excludes the same noisy/generated paths as the other PHPForge configs, and collects project classes through a generic `Project` layer instead of hard-coding a package namespace. Publish it when a project is ready to split that baseline into real domain, package, or framework layers.

```bash
composer ic:test:architecture
composer ic:init --deptrac
composer ic:publish-config deptrac.yaml
```

Check active config sources:

```bash
composer ic:list-config
composer ic:list-config --json
```

Publish config only when a project needs custom rules:

```bash
composer ic:publish-config phpforge.json pint.json phpstan.neon.dist
composer ic:publish-config --all
```

Supported publishable config files:

```text
captainhook.json
deptrac.yaml
pest.xml
phpunit.xml
phpbench.json
phpcs.xml.dist
phpforge.json
phpstan.neon.dist
pint.json
psalm.xml
rector.php
```

Use `--force` to overwrite existing files:

```bash
composer ic:publish-config psalm.xml --force
```

## Environment Variables

| Variable                    | Default | Purpose                                                                                                  |
| --------------------------- | ------- | -------------------------------------------------------------------------------------------------------- |
| `IC_PEST_PROCESSES`         | `10`    | Controls Pest parallel processes for `ic:tests`.                                                         |
| `IC_TEST_CONCURRENCY`       | `3`     | Controls the maximum concurrently running tools for `ic:tests:parallel`.                                 |
| `PHPFORGE_PARALLEL`         | `3`     | Alias for `IC_TEST_CONCURRENCY`; useful in generic CI parallelism settings.                              |
| `PHPFORGE_QUALITY_SUMMARY`  | none    | Writes an aggregate per-tool quality result JSON file for `ic:ci`, `ic:tests`, and `ic:tests:parallel`.  |
| `IC_QUALITY_SUMMARY`        | none    | Alias for `PHPFORGE_QUALITY_SUMMARY`.                                                                    |
| `IC_PHPSTAN_MEMORY_LIMIT`   | `1G`    | Controls PHPStan memory limit.                                                                           |
| `IC_PSALM_THREADS`          | `1`     | Controls Psalm thread count.                                                                             |
| `IC_HOOKS_STRICT`           | `1`     | Fails Composer when automatic CaptainHook install fails. Set to `0` for best-effort hook installation.   |

Example:

```bash
IC_PEST_PROCESSES=4 composer ic:tests
IC_TEST_CONCURRENCY=4 composer ic:tests:parallel
PHPFORGE_QUALITY_SUMMARY=var/quality.json composer ic:ci
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
composer normalize --dry-run
composer ic:release:audit
composer ic:ci
```

This package also has a root `post-autoload-dump` script:

```json
"post-autoload-dump": "@php bin/install-captainhook.php"
```

That helper keeps hooks installed for this repository. Consuming projects get automatic hook installation from the PHPForge Composer plugin: it uses project `captainhook.json` when present, otherwise it copies the bundled `captainhook.json` into project root and installs hooks from there.

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
      php_versions: '["8.2","8.3","8.4","8.5"]'
      dependency_versions: '["prefer-lowest","prefer-stable"]'
      php_extensions: ""
      coverage: "none"
      composer_flags: ""
      phpstan_memory_limit: "1G"
      psalm_threads: "1"
      run_analysis: true
      run_svg_report: true
      artifact_retention_days: 61
```

Workflow inputs:

| Input                       | Default                               | Purpose                                                                                                                               |
| --------------------------- | ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `php_versions`            | `["8.2","8.3","8.4","8.5"]`         | PHP matrix as a JSON array string.                                                                                                    |
| `dependency_versions`     | `["prefer-lowest","prefer-stable"]` | Composer dependency modes as a JSON array string.                                                                                     |
| `php_extensions`          | `""`                                | Comma-separated PHP extensions passed to `shivammathur/setup-php`.                                                                  |
| `coverage`                | `none`                              | Coverage driver passed to `shivammathur/setup-php`; use `xdebug`, `pcov`, or `none`.                                          |
| `composer_flags`          | `""`                                | Extra flags appended to Composer install/update commands.                                                                             |
| `phpstan_memory_limit`    | `1G`                                | PHPStan memory limit used by workflow analysis.                                                                                       |
| `psalm_threads`           | `1`                                 | Psalm thread count used by workflow analysis.                                                                                         |
| `run_analysis`            | `true`                              | Runs SARIF upload jobs for PHPStan and Psalm. Set to `false` for CI-only runs.                                                      |
| `run_svg_report`          | `true`                              | Generates `security-report.svg` and `security-summary.json` with benchmark status, per-version matrix results, and tool versions. |
| `artifact_retention_days` | `61`                                | Artifact retention days for uploaded `security-report` artifacts.                                                                   |

### Workflow Input Details

`php_versions` must be a JSON array string because reusable workflow inputs are strings:

```yaml
with:
  php_versions: '["8.2","8.3","8.4","8.5"]'
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

Normal workflow workers run `composer ic:ci`, which delegates to the same bounded parallel runner as `ic:tests:parallel`.
When the matrix entry is `prefer-lowest`, PHPForge still runs `composer ic:ci --prefer-lowest`, skipping heavyweight PHPStan and Psalm checks for that dependency edge.

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

`artifact_retention_days` controls how long uploaded report artifacts are kept:

```yaml
with:
  artifact_retention_days: 61
```

Set a shorter value for active development branches or a longer value for scheduled/release runs.

When enabled on `main` or `master`, the workflow uploads one artifact:

- `security-report` (contains `security-report.svg` and `security-summary.json`)

`security-summary.json` includes:

- `tested_php_versions`
- `matrix_results` (per PHP version: `code_analysis_prefer_lowest`, `code_analysis_prefer_stable`, `security_analysis`)
- `quality_results` (uploaded per-tool JSON summaries from CI workers when available)
- `benchmark_result`
- `benchmark_command`
- `benchmark_php_version`
- `tools` (tool `name`, package, resolved version)

`security-report.svg` renders the same high-level status, per-version matrix check results, per-tool quality chips when quality summaries are available, and resolved tool versions.

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
      php_versions: '["8.2","8.3","8.4","8.5"]'
      dependency_versions: '["prefer-lowest","prefer-stable"]'
      run_analysis: true
```

Project with extensions and no SARIF upload:

```yaml
jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    with:
      php_versions: '["8.2","8.3"]'
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
      php_versions: '["8.2","8.3"]'
      dependency_versions: '["prefer-stable"]'
      php_extensions: "mbstring, intl, bcmath, pdo_mysql"
      coverage: "xdebug"
      composer_flags: "--ignore-platform-req=ext-redis"
      phpstan_memory_limit: "2G"
      psalm_threads: "2"
      run_analysis: true
```

For code scanning, project-local PHPStan configs (`phpstan.neon`, then `phpstan.neon.dist`) and Psalm configs (`psalm.xml`, then `psalm.xml.dist`) are used when present; otherwise the workflow falls back to PHPForge defaults.

## Other CI Platforms

Generate starter CI files with `ic:init`:

```bash
composer ic:init --gitlab-ci
composer ic:init --bitbucket-ci
composer ic:init --forgejo-workflow
```

Generated files:

- `.gitlab-ci.yml` (GitLab CI)
- `bitbucket-pipelines.yml` (Bitbucket Pipelines)
- `.forgejo/workflows/security-standards.yml` (Forgejo Actions)

Each template installs dependencies and runs:

```bash
composer ic:ci
```

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
    "infocyph/phpforge": "dev-main"
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
| `composer test:duplicates`                    | `composer ic:test:duplicates`   |
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
composer ic:test:duplicates
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

Then open the workflow run and download `security-report`.

### A bundled rule is too strict

Publish the relevant config and edit it in the project:

```bash
composer ic:publish-config phpforge.json
composer ic:publish-config phpstan.neon.dist
composer ic:publish-config psalm.xml
```

Project config files always take priority over bundled defaults.
For syntax or duplicate detector noise, adjust `phpforge.json` paths/excludes or duplicate thresholds first.
