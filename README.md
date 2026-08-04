# PHPForge

[![PHPForge](https://github.com/infocyph/PHPForge/actions/workflows/phpforge.yml/badge.svg)](https://github.com/infocyph/PHPForge/actions/workflows/phpforge.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/PHPForge?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2FPHPForge)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/PHPForge)

Reusable Composer-powered QA, refactoring, benchmark, release, hook and CI tooling for PHP libraries and applications.

PHPForge is installed as a dev dependency in PHP libraries and packages. It provides Composer commands under the `ic:*` namespace, ships default tool configuration, installs CaptainHook hooks, exposes a reusable GitHub Actions workflow and includes starter templates for GitLab CI, Bitbucket Pipelines and Forgejo Actions.

## What It Includes

PHPForge brings these tools through one package:

| Tool                        | Used For                                            |
| --------------------------- | --------------------------------------------------- |
| CaptainHook                 | Git hook installation and pre-commit checks         |
| Pest                        | Test execution                                      |
| Laravel Pint                | Code style checks and fixes                         |
| PHP_CodeSniffer / PHPCBF    | Semantic sniffing and fixable sniff repairs         |
| PHPProbe                    | Git-aware PHP syntax, duplicate-code, and comment-policy checks |
| Deptrac                     | Architecture boundary checks                        |
| PHPStan                     | Static analysis and cognitive complexity            |
| Psalm                       | Security and taint analysis                         |
| Rector                      | Refactor checks and automated refactors             |
| PHPBench                    | Benchmarks                                          |
| Composer Normalize          | `composer.json` normalization                     |
| Composer audit              | Release/security audit guard                        |

## Engineering Baseline

PHPForge's current `dev-main` line targets PHP 8.4 and later, uses PSR-4 autoloading, and formats first-party PHP against
[PER Coding Style 3.0](https://www.php-fig.org/per/coding-style/) through the configured Pint toolchain.
PHPProbe 0.7 provides the syntax, duplicate-code, and comment-policy checks used by PHPForge.
Bundled Pest and PHPUnit configurations run with every PHP error level enabled so deprecations remain
visible during compatibility testing.

## Install

Check the consuming project's PHP version before selecting the PHPForge line:

```bash
php -r 'echo PHP_VERSION, PHP_EOL;'
```

| PHPForge line | Minimum PHP | Intended use                                |
| ------------- |---------|---------------------------------------------|
| `dev-main@dev` | PHP 8.4 | Current development line and newest tooling |
| `^1.0` | PHP 8.2 | Stable 1.x line for PHP 8.2-8.5             |

Install the current development line on PHP 8.4 or later:

```bash
composer require --dev infocyph/phpforge:dev-main@dev
```

Use the stable 1.x line when the project must remain compatible with PHP 8.2
or PHP 8.3:

```bash
composer require --dev infocyph/phpforge:^1.0
```

Composer enforces the selected line's PHP constraint and rejects an
incompatible runtime.

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
Install GitLab CI pipeline (.gitlab-ci.yml)?
Install Bitbucket pipeline (bitbucket-pipelines.yml)?
Install Forgejo workflow (.forgejo/workflows/security-standards.yml)?
Install generic contributing, issue, and pull request templates?
PHPForge workflow ref
PHP version matrix
Dependency matrix
PHP extensions
Extra Composer flags
PHPStan memory limit
Psalm threads
Enable SARIF code-scanning analysis job?
Generate SVG security and quality report artifacts?
Enable Redis service container in workflow run job?
Enable Valkey service container in workflow run job?
Enable Memcached service container in workflow run job?
Enable PostgreSQL service container in workflow run job?
Enable MySQL service container in workflow run job?
Enable ScyllaDB Alternator service container in workflow run job?
Enable Elasticsearch service container in workflow run job?
Enable MongoDB service container in workflow run job?
Shared service database name:
Shared service username:
Shared service password:
```

`service_db_*` prompts are always shown once workflow setup is selected so one credential set can be reused across services.

Selector presets include:

| Prompt                | Built-in Choices                                                                                                                                                                                   |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHPForge workflow ref | `main`, configured ref, or custom                                                                                                                                                                |
| PHP version matrix    | `supported`, `current`, `stable`, or custom JSON. Presets resolve live with fallback to `["8.4","8.5"]`.                                                                                   |
| Dependency matrix     | `full` => `["prefer-lowest","prefer-stable"]`, `stable` => `["prefer-stable"]`, or custom JSON. Prompt shows resolved JSON beside each option.                                             |
| PHP extensions        | `none` => `""`, `detected` (from project `composer.json` `ext-*` entries in `require`, `require-dev`, and `suggest`), `common`, `mysql`, `pgsql`, `mysql+pgsql`, or custom |
| Extra Composer flags  | `none` => `""`, `with-all-dependencies` => `--with-all-dependencies`, `ignore-ext-redis` => `--ignore-platform-req=ext-redis`, or custom. Prompt explains each option effect.          |
| PHPStan memory limit  | `1G`, `2G`, `4G`, or custom                                                                                                                                                                  |
| Psalm threads         | `1`, `2`, `4`, or custom                                                                                                                                                                     |

`supported` includes non-EOL PHP minor cycles (>= `8.4`), `current` uses the latest two supported cycles, and `stable` uses the latest supported cycle.
When detected `ext-*` entries exist in `composer.json`, the PHP extensions selector defaults to the detected preset.
PHP version, dependency matrix, PHP extensions, and Composer flags selectors show resolved values in the prompt and print the final resolved value after selection.

Depending on your selections, `ic:init` can generate:

```text
captainhook.json
.github/workflows/security-standards.yml
.gitlab-ci.yml
bitbucket-pipelines.yml
.forgejo/workflows/security-standards.yml
CONTRIBUTING.md
CODE_OF_CONDUCT.md
SECURITY.md
.github/ISSUE_TEMPLATE/bug_report.yml
.github/ISSUE_TEMPLATE/ci_failure.yml
.github/ISSUE_TEMPLATE/feature_request.yml
.github/ISSUE_TEMPLATE/question.yml
.github/ISSUE_TEMPLATE/docs_improvement.yml
.github/ISSUE_TEMPLATE/config.yml
.github/PULL_REQUEST_TEMPLATE.md
```

`ic:init` sets up hook/workflow wrappers and optional community template files. Publish checker or architecture config separately with `composer ic:publish-config phpprobe.json deptrac.yaml` when customization is needed.

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
composer ic:init --workflow --workflow-ref=main
composer ic:init --gitlab-ci
composer ic:init --bitbucket-ci
composer ic:init --forgejo-workflow
composer ic:init --community-templates
composer ic:init --no-interaction-defaults
composer ic:init --force
composer ic:int
```

## Command Reference

### AI Mod

- `vendor/infocyph/phpforge/resources/engineering-principles.md`
  Brief engineering principles for AI-assisted implementation decisions, code quality, performance, security, and scope control.
- `vendor/infocyph/phpforge/resources/AGENTS.md`
  Task execution workflow for agents, including which PHPForge commands to run first, validation flow, and automation expectations.

### Test Commands

| Command                         | Purpose                                                                                                                                                        |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `composer ic:tests`           | Full project quality suite: syntax, Pest parallel tests, Pint check, PHPCS summary, duplicate detection, comment policy checks, Deptrac, PHPStan, Psalm security analysis, and Rector dry run. |
| `composer ic:tests:all`       | Alias of `ic:tests`.                                                                                                                                         |
| `composer ic:tests:parallel`  | Runs syntax first, then executes the remaining quality checks with bounded parallelism and a buffered PASS/FAIL summary.                                       |
| `composer ic:tests:details`   | Runs detailed checks without the parallel Pest shortcut.                                                                                                       |
| `composer ic:test:syntax`     | Runs the PHP syntax checker using `phpprobe.json`, Git ignores, and configured excludes.                                                                     |
| `composer ic:test:code`       | Runs Pest when the project has a `tests/` directory; otherwise skips it.                                                                                     |
| `composer ic:test:lint`       | Runs Pint in check mode.                                                                                                                                       |
| `composer ic:test:sniff`      | Runs PHPCS with a full report against the project root and bundled/project excludes.                                                                           |
| `composer ic:test:duplicates` | Runs duplicate detection using `phpprobe.json`.                                                                                                              |
| `composer ic:test:probe`      | Runs aggregate PHPProbe checks (syntax, duplicates, comments) using `phpprobe.json`.                                                                       |
| `composer ic:test:comments`   | Runs comment policy checks using `phpprobe.json`.                                                                                                            |
| `composer ic:test:architecture` | Runs Deptrac architecture checks using `deptrac.yaml`.                                                                                                    |
| `composer ic:test:static`     | Runs PHPStan.                                                                                                                                                  |
| `composer ic:test:security`   | Runs Psalm security analysis.                                                                                                                                  |
| `composer ic:test:refactor`   | Runs Rector in dry-run mode.                                                                                                                                   |
| `composer ic:test:bench`      | Runs PHPBench aggregate benchmarks when the project has a `benchmarks/` directory; otherwise skips them.                                                     |

Syntax, duplicate, and comment settings live in `phpprobe.json`, with the bundled default used when a project-local file is not present.
PHPForge delegates these checks to `vendor/bin/phpprobe`; the `phpforge syntax`, `phpforge duplicates`, `phpforge comments`, and `phpforge check` commands are thin gateways that pass the same config to PHPProbe.
By default the bundled config uses PHPProbe's standard syntax and duplicate profiles with the strict comment policy. Duplicate findings remain visible, but become blocking only when duplicated lines reach 10% of the scanned code. Projects can still override individual sections in a published `phpprobe.json`.
Use the lower-level binary for custom scans; CLI paths override configured paths, while CLI excludes are added to configured excludes:

```bash
php vendor/bin/phpprobe syntax --config=phpprobe.json --exclude=storage
php vendor/bin/phpprobe check --config=phpprobe.json
php vendor/bin/phpprobe duplicates --config=phpprobe.json --min-lines=5 --min-tokens=70
php vendor/bin/phpprobe duplicates --config=phpprobe.json --mode=audit --near-miss --json --exclude=tests
php vendor/bin/phpprobe comments --config=phpprobe.json --fail-on=warning
php vendor/bin/phpprobe comments --config=phpprobe.json --ci
php vendor/bin/phpprobe duplicates --config=phpprobe.json --write-baseline=.phpprobe-duplicates-baseline.json
php vendor/bin/phpprobe duplicates --config=phpprobe.json --baseline=.phpprobe-duplicates-baseline.json
```

Useful checker options:

| Option                      | Applies To         | Purpose                                                                 |
| --------------------------- | ------------------ | ----------------------------------------------------------------------- |
| `--config=FILE`           | Syntax, duplicates, comments, check | Reads checker settings from a custom `phpprobe.json` file.       |
| `--preset=NAME`           | Syntax, duplicates, comments, check | Applies a runtime preset (`default`, `standard`, `ci`, `strict`). |
| `--exclude=PATH`          | Syntax, duplicates, comments | Excludes one path; repeat it for multiple one-off exclusions.           |
| `--exact`                 | Duplicates         | Disables variable/literal normalization.                                |
| `--fuzzy`                 | Duplicates         | Also normalizes identifiers and calls for renamed-code scans.           |
| `--mode=audit`            | Duplicates         | Enables statement-window matching in addition to token matching.        |
| `--near-miss`             | Duplicates         | Enables bounded statement/shape similarity for edited clones.           |
| `--min-lines=N`           | Duplicates         | Sets the minimum duplicated line span.                                  |
| `--min-tokens=N`          | Duplicates         | Sets the token fingerprint window size.                                 |
| `--min-statements=N`      | Duplicates         | Sets the structural statement window size for audit matching.           |
| `--min-similarity=0.85`   | Duplicates         | Sets the near-miss similarity threshold.                                |
| `--baseline=FILE`         | Duplicates, comments | Suppresses known clone groups or comment findings.                    |
| `--write-baseline[=FILE]` | Duplicates, comments | Writes duplicate-clone or comment baselines and exits successfully.   |
| `--strict`                | Comments           | Escalates commented-out-code policy severities.                         |
| `--ci`                    | Comments           | Emits only error-level findings (clean CI logs).                        |
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
| `composer ic:benchmark`   | Runs PHPBench aggregate benchmarks when `benchmarks/` exists. |
| `composer ic:bench:run`   | Alias of `ic:benchmark`.          |
| `composer ic:bench:quick` | Runs a shorter PHPBench pass.       |
| `composer ic:bench:chart` | Runs PHPBench chart report.         |
| `composer ic:benchmark:validate result.json` | Validates a workload-neutral representative benchmark result. |
| `composer ic:benchmark:compare baseline.json candidate.json --stable-environment` | Enforces a like-for-like successful-RPM regression budget; defaults to 2%. |
| `composer ic:soak:worker --duration=300 -- command [args...]` | Soak-tests any long-running web or queue worker for early exit and RSS growth. |

### Release Commands

| Command                       | Purpose                                                                 |
| ----------------------------- | ----------------------------------------------------------------------- |
| `composer ic:release:audit` | Runs Composer audit. Security advisories fail; abandoned packages warn. |
| `composer ic:release:constraints` | Rejects development branches, aliases, commit references, pre-stable flags, and non-stable minimum stability in runtime requirements. |
| `composer ic:release:guard` | Runs Composer validation, stable runtime constraints, audit, and the full test suite. |

### Representative Benchmark Contract

PHPForge can be used by any PHP library or application. Its representative result contract is therefore workload-neutral: producers map component operations, HTTP requests, persistent-worker work, queue jobs, or custom operations into the same fields. PHPForge validates and compares the result; it does not own a framework-specific load generator.

The schema is installed at `vendor/infocyph/phpforge/resources/benchmark-result.schema.json`. A minimal complete document has this shape:

```json
{
  "schema_version": 1,
  "generated_at": "2026-07-29T12:00:00+00:00",
  "environment": {
    "stable": true,
    "fingerprint": "dedicated-runner-a",
    "php_version": "8.4.23",
    "php_sapi": "cli",
    "operating_system": "Linux 6.8",
    "cpu_model": "Dedicated benchmark CPU",
    "memory_limit": "-1",
    "opcache": false,
    "jit": false,
    "xdebug": false,
    "extensions": ["json"],
    "runner": "project-runner 1.0",
    "release": "4b6"
  },
  "workloads": [
    {
      "name": "map-and-filter",
      "type": "component",
      "metadata": {
        "fixture_size": 1000
      },
      "repetitions": 3,
      "warmup_operations": 100,
      "duration_seconds": 30,
      "concurrency": 1,
      "result": {
        "attempted_operations": 30000,
        "successful_operations": 30000,
        "failed_operations": 0,
        "timeouts": 0,
        "successful_rpm": 60000,
        "error_rate": 0,
        "latency_ms": {
          "minimum": 0.5,
          "average": 0.8,
          "p50": 0.7,
          "p95": 1,
          "p99": 1.2,
          "maximum": 2
        },
        "cpu": {
          "average_percent": 90,
          "peak_percent": 100
        },
        "memory": {
          "average_mb": 20,
          "peak_mb": 24,
          "growth_mb": 0.5
        },
        "stability": {
          "status": "stable",
          "spread_percent": 1
        }
      }
    }
  ]
}
```

Use `null` for unavailable latency, CPU, or memory measurements; do not invent zero values. `metadata` is producer-owned and records the data size, request mix, queue shape, cache state, or other workload inputs needed for reproduction.

The contract uses these workload-neutral meanings:

| Field | Meaning |
| --- | --- |
| `environment.fingerprint` | A producer-defined identity for the benchmark host and relevant runtime configuration. Change it when hardware, PHP, extensions, or tuning changes. |
| `environment.stable` | `true` only for controlled infrastructure where repeated results are suitable for a release gate. |
| `workloads[].type` | One of `component`, `http`, `persistent-worker`, `queue-worker`, or `custom`; it selects no framework behavior. |
| `metadata` | Reproduction inputs owned by the producer, including what one operation represents. |
| `repetitions` | Number of independent measured repetitions represented by the result. |
| `warmup_operations` | Operations completed before measurement; excluded from result counters. |
| `duration_seconds` | Total measured duration represented by the result. |
| `attempted_operations` | All measured operations; it must equal successful plus failed operations. |
| `successful_rpm` | Successful operations per minute, regardless of whether an operation is a function call, request, message, or job. |
| `error_rate` | Failed operations divided by attempted operations, expressed from `0` to `1`. |
| `stability` | The producer's repeated-sample assessment and spread; regression enforcement requires `stable`. |

Validation always checks counters, failure/timeout bounds, percentile order, resource values, unique workload names, and required environment metadata:

```bash
composer ic:benchmark:validate build/benchmark-result.json
```

Regression enforcement is intentionally opt-in:

```bash
composer ic:benchmark:compare \
  benchmarks/baseline.json \
  build/benchmark-result.json \
  --max-regression=2 \
  --stable-environment
```

Without `--stable-environment`, comparison validates both documents and exits successfully with a skipped notice. With it, both documents must declare `environment.stable: true`, use the same environment fingerprint and runtime metadata, contain the same workload settings, and report stable samples. The gate compares successful RPM and rejects increased error rate; it never turns noisy shared-runner output into a release failure.

The generic soak helper monitors the direct worker process on Linux without retaining worker output in PHPForge memory:

```bash
composer ic:soak:worker \
  --duration=900 \
  --warmup=5 \
  --sample-interval=1 \
  --max-rss-mb=256 \
  --max-growth-mb=16 \
  --report=build/worker-soak.json \
  -- php bin/worker.php
```

The command applies equally to persistent application servers and queue consumers. The project remains responsible for providing representative traffic or queued work while the worker runs.

Use the `composer ic:*` commands in consuming packages. PHPForge does not require those packages to add `composer/composer` as a runtime dependency; Composer supplies the plugin command runtime.

### Config And Utility Commands

| Command                                               | Purpose                                                                                                        |
| ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `composer ic:init`                                  | Interactively sets up CaptainHook pre-commit and workflow wrappers.               |
| `composer ic:init --captainhook`                    | Copies only the CaptainHook pre-commit config.                                                               |
| `composer ic:init --workflow --workflow-ref=main`   | Copies only the GitHub Actions wrapper and points it at the given PHPForge ref.                               |
| `composer ic:init --gitlab-ci`                      | Copies `.gitlab-ci.yml` starter pipeline.                                                                    |
| `composer ic:init --bitbucket-ci`                   | Copies `bitbucket-pipelines.yml` starter pipeline.                                                           |
| `composer ic:init --forgejo-workflow`               | Copies `.forgejo/workflows/security-standards.yml` starter workflow.                                         |
| `composer ic:init --community-templates`            | Copies generic `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, issue forms/config, and PR template files. |
| `composer ic:init --no-interaction-defaults`        | Copies default init files without prompting.                                                                   |
| `composer ic:init --force`                          | Overwrites existing copied files.                                                                              |
| `composer ic:int`                                   | Alias of `composer ic:init`.                                                                                   |
| `composer ic:hooks`                                 | Installs enabled CaptainHook hooks.                                                                            |
| `composer ic:doctor`                                | Shows detected configs, vendor-dir, plugin permissions, hook status, and workflow wrapper validation warnings. |
| `composer ic:doctor --json`                         | Outputs doctor diagnostics as JSON, including workflow wrapper validation details.                             |
| `composer ic:list-config`                           | Lists config files and their resolution source.                                                                |
| `composer ic:list-config --json`                    | Outputs config resolution as JSON.                                                                             |
| `composer ic:active-config [file...]`               | Shows the active configs for supported tools, with file selection similar to `ic:publish-config`.              |
| `composer ic:active-config --json`                  | Outputs all active config summaries as JSON.                                                                   |
| `composer ic:active-config phpstan.neon.dist --parameter=cognitive_complexity --json` | Filters to one active config file and one parameter/effective key.                    |
| `composer ic:publish-config [file...]`              | Copies selected bundled config files into the project.                                                         |
| `composer ic:publish-config phpprobe.json --phpprobe-preset=strict` | Publishes `phpprobe.json` with a named PHPProbe preset (`default`, `standard`, `ci`, `strict`). |
| `composer ic:publish-config --all`                  | Copies every bundled config file into the project.                                                             |
| `composer ic:publish-config --all --force`          | Overwrites all project config files with bundled defaults.                                                     |
| `composer ic:community`                             | Copies generic `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, issue forms/config, and PR template files into the project. |
| `composer ic:community --force`                     | Overwrites existing community template files with bundled defaults.                                            |
| `composer ic:publish-community-templates`           | Alias of `composer ic:community`.                                                                              |
| `composer ic:publish-community-templates --force`   | Alias of `composer ic:community --force`.                                                                      |
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
| Pest / PHPUnit         | `pest.xml`, `pest.xml.dist`, `phpunit.xml`, `phpunit.xml.dist`, then the first matching bundled config |
| PHPBench               | `phpbench.json`, then `phpbench.json.dist`, then the first matching bundled config                          |
| PHPProbe checker tasks | `phpprobe.json`, then bundled `phpprobe.json`                                                                |
| Deptrac                | `deptrac.yaml`, then bundled `deptrac.yaml`                                                                  |
| PHPCS / PHPCBF         | `phpcs.xml.dist`, then bundled `phpcs.xml.dist`                                                              |
| PHPStan                | `phpstan.neon`, then `phpstan.neon.dist`, then the first matching bundled config                            |
| Pint                   | `pint.json`, then bundled `pint.json`                                                                        |
| Psalm                  | `psalm.xml`, then `psalm.xml.dist`, then the first matching bundled config                                  |
| Rector                 | `rector.php`, then bundled `rector.php`                                                                      |
| CaptainHook            | `captainhook.json`, then bundled `captainhook.json`                                                          |

### PHPProbe Checker Config

`phpprobe.json` configures PHPProbe syntax, duplicate-code, and comment-policy checks.
PHPProbe 0.7 is preset-first, and PHPForge follows that model.

Bundled default:

```json
{
  "preset": "standard",
  "duplicates": {
    "fail_on": "error",
    "error_duplicate_percentage": 10
  },
  "commented_out_code": {
    "policy": "strict"
  }
}
```

You can still add section overrides (`syntax`, `duplicates`, `comments`, `commented_out_code`) when a project needs custom thresholds or exclusions.

Presets for `phpprobe.json` publishing:

| Preset      | Summary |
| ----------- | ------- |
| `default`   | Raw engine defaults. |
| `standard`  | Recommended balanced preset. |
| `ci`        | Quieter CI gate with stricter duplicate thresholds. |
| `strict`    | Sensitive audit preset across duplicates and comments. |

```bash
composer ic:publish-config phpprobe.json --phpprobe-preset=strict
```

The publishing option above intentionally opts every PHPProbe detector into its
strict analysis profile. PHPForge's bundled default narrows blocking strictness
to comment policy: duplicate analysis remains fully enabled and reported, while
its gate fails only when duplicated lines reach the configured 10% error
threshold.

### Deptrac Architecture Config

`deptrac.yaml` configures architecture dependency boundaries. The bundled default scans from the project root, excludes the same noisy/generated paths as the other PHPForge configs, and collects project classes through a generic `Project` layer instead of hard-coding a package namespace. Publish it when a project is ready to split that baseline into real domain, package, or framework layers.

```bash
composer ic:test:architecture
composer ic:publish-config deptrac.yaml
```

Check active config sources:

```bash
composer ic:list-config
composer ic:list-config --json
composer ic:active-config
composer ic:active-config --json
composer ic:active-config phpcs.xml.dist
composer ic:active-config phpstan.neon.dist --parameter=cognitive_complexity --json
```

When selecting a file, pass the filename directly, for example `composer ic:active-config phpcs.xml.dist`.
If you insist on a leading `--` token, Composer requires a separator first: `composer ic:active-config -- --phpcs.xml.dist`.

Publish config only when a project needs custom rules:

```bash
composer ic:publish-config phpprobe.json pint.json phpstan.neon.dist
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
phpprobe.json
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
      php_versions: '["8.4","8.5"]'
      dependency_versions: '["prefer-lowest","prefer-stable"]'
      php_extensions: ""
      composer_flags: ""
      phpstan_memory_limit: "1G"
      psalm_threads: "1"
      run_analysis: true
      run_svg_report: true
      fail_on_skipped_tests: false
      run_clean_install: true
      benchmark_composer_script: ""
      benchmark_result_file: ""
      benchmark_baseline_file: ""
      benchmark_max_regression_percent: 2
      benchmark_stable_environment: false
      enable_redis_service: false
      enable_valkey_service: false
      enable_memcached_service: false
      enable_postgres_service: false
      enable_mysql_service: false
      enable_scylladb_service: false
      enable_elasticsearch_service: false
      enable_mongodb_service: false
      service_db_name: "phpforge"
      service_db_user: "phpforge"
      service_db_password: "phpforge"
      artifact_retention_days: 61
```

Workflow inputs:

| Input                       | Default                               | Purpose                                                                                                                               |
| --------------------------- | ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `php_versions`            | `["8.4","8.5"]`                         | PHP matrix as a JSON array string. Unsupported versions are silently omitted before jobs are created.                                |
| `dependency_versions`     | `["prefer-lowest","prefer-stable"]` | Composer dependency modes as a JSON array string.                                                                                     |
| `php_extensions`          | `""`                                | Comma-separated PHP extensions passed to `shivammathur/setup-php`.                                                                  |
| `composer_flags`          | `""`                                | Extra flags appended to Composer install/update commands.                                                                             |
| `phpstan_memory_limit`    | `1G`                                | PHPStan memory limit used by workflow analysis.                                                                                       |
| `psalm_threads`           | `1`                                 | Psalm thread count used by workflow analysis.                                                                                         |
| `run_analysis`            | `true`                              | Runs SARIF upload jobs for PHPStan and Psalm. Set to `false` for CI-only runs.                                                      |
| `run_svg_report`          | `true`                              | Generates `security-report.svg` and `security-summary.json` with per-version matrix results, per-version benchmark timings/trends, and tool versions. |
| `fail_on_skipped_tests`   | `false`                             | Adds `--fail-on-skipped` to Pest execution so skipped tests fail the CI test job.                                                    |
| `run_clean_install`       | `true`                              | Verifies a production-style `--no-dev` install and authoritative autoload on the final configured PHP version.                      |
| `benchmark_composer_script` | `""`                              | Optional Composer script name that produces a representative result. Empty keeps the existing PHPBench discovery behavior.           |
| `benchmark_result_file`   | `""`                                | Optional workload-neutral result JSON file validated after the benchmark script.                                                     |
| `benchmark_baseline_file` | `""`                                | Optional repository baseline compared with `benchmark_result_file`.                                                                  |
| `benchmark_max_regression_percent` | `2`                       | Maximum successful-RPM regression for the stable-environment gate.                                                                   |
| `benchmark_stable_environment` | `false`                         | Enables regression failure only when result metadata also proves the same stable environment.                                        |
| `enable_redis_service`    | `false`                             | Starts a Redis service container and waits for readiness before running test commands.                                               |
| `enable_valkey_service`   | `false`                             | Starts a Valkey service container and waits for readiness before running test commands.                                              |
| `enable_memcached_service`| `false`                             | Starts a Memcached service container and waits for readiness before running test commands.                                           |
| `enable_postgres_service` | `false`                             | Starts a PostgreSQL service container and waits for readiness before running test commands.                                          |
| `enable_mysql_service`    | `false`                             | Starts a MySQL service container and waits for readiness before running test commands.                                               |
| `enable_scylladb_service` | `false`                             | Starts a ScyllaDB Alternator service container and waits for readiness before running test commands.                                 |
| `enable_elasticsearch_service` | `false`                        | Starts an Elasticsearch service container and waits for readiness before running test commands.                                      |
| `enable_mongodb_service`  | `false`                             | Starts a MongoDB service container and waits for readiness before running test commands.                                             |
| `service_db_name`         | `phpforge`                          | Shared database name for PostgreSQL/MySQL/MongoDB service containers.                                                                |
| `service_db_user`         | `phpforge`                          | Shared username reused across service containers and exported client env vars.                                                       |
| `service_db_password`     | `phpforge`                          | Shared password reused across service containers and exported client env vars.                                                       |
| `artifact_retention_days` | `61`                                | Artifact retention days for uploaded `security-report` artifacts.                                                                   |

### Workflow Input Details

`php_versions` must be a JSON array string because reusable workflow inputs are strings:

```yaml
with:
  php_versions: '["8.4","8.5"]'
```

PHPForge currently supports the `8.4` and `8.5` cycles. Unsupported entries are
silently removed from the matrix; when no supported entry remains, PHP-dependent
jobs are skipped successfully. Exact patch releases within a supported cycle,
such as `8.4.12`, are accepted.

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
The CI path uses `phpprobe comments --ci`, so comment-policy output stays error-focused in workflow logs.
When the matrix entry is `prefer-lowest`, PHPForge still runs `composer ic:ci --prefer-lowest`, skipping heavyweight PHPStan and Psalm checks for that dependency edge.

`php_extensions` is passed to `shivammathur/setup-php`:

```yaml
with:
  php_extensions: "mbstring, intl, bcmath, pdo_mysql, pdo_pgsql"
```

When `apcu` is included in `php_extensions`, the reusable workflow automatically enables APCu for CLI (`apc.enable_cli=1`, `apcu.enable_cli=1`) so APCu-backed tests can run in CI.

Leave it empty when no extra extensions are needed:

```yaml
with:
  php_extensions: ""
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

Optional integration services are disabled by default:

```yaml
with:
  enable_redis_service: true
  enable_valkey_service: false
  enable_memcached_service: true
  enable_postgres_service: true
  enable_mysql_service: false
  enable_scylladb_service: true
  enable_elasticsearch_service: true
  enable_mongodb_service: true
  service_db_name: "cachelayer"
  service_db_user: "phpforge"
  service_db_password: "phpforge"
```

When a service is enabled, the workflow exports these environment variables in the `run` job:

- `IC_REDIS_HOST`, `IC_REDIS_PORT`, `IC_REDIS_PASSWORD`
- `IC_VALKEY_HOST`, `IC_VALKEY_PORT`, `IC_VALKEY_PASSWORD`
- `IC_SERVICE_DATABASE`, `IC_SERVICE_USERNAME`, `IC_SERVICE_PASSWORD`
- `IC_MEMCACHED_HOST`, `IC_MEMCACHED_PORT`
- `IC_POSTGRES_DSN`, `IC_POSTGRES_USER`, `IC_POSTGRES_PASSWORD`
- `IC_MYSQL_DSN`, `IC_MYSQL_USER`, `IC_MYSQL_PASSWORD`
- `IC_SCYLLADB_HOST`, `IC_SCYLLADB_PORT`, `IC_SCYLLADB_ENDPOINT`, `IC_SCYLLADB_REGION`, `IC_SCYLLADB_ACCESS_KEY_ID`, `IC_SCYLLADB_SECRET_ACCESS_KEY`
- `IC_ELASTICSEARCH_HOST`, `IC_ELASTICSEARCH_PORT`, `IC_ELASTICSEARCH_URL`
- `IC_MONGODB_HOST`, `IC_MONGODB_PORT`, `IC_MONGODB_DSN`

`run_analysis` controls the dedicated Composer audit, PHPStan, Psalm, and SARIF
analysis job:

```yaml
with:
  run_analysis: false
```

SARIF publication is best-effort. A repository without GitHub code scanning or
Advanced Security still runs the audit and local analysis gates; an unavailable
upload does not fail the job. Set `run_analysis: false` only when the entire
dedicated analysis job should be skipped.

`run_svg_report` controls the SVG reporting artifact job:

```yaml
with:
  run_svg_report: true
```

`fail_on_skipped_tests` makes skipped Pest tests fail CI:

```yaml
with:
  fail_on_skipped_tests: true
```

`run_clean_install` adds a separate release-install check. It selects the last entry in `php_versions`, installs from a clean checkout with `--no-dev --optimize-autoloader`, checks platform requirements, and verifies an authoritative classmap:

```yaml
with:
  run_clean_install: true
```

Representative benchmark integration is optional and works with any PHP library:

```yaml
with:
  benchmark_composer_script: "benchmark:representative"
  benchmark_result_file: "build/benchmark-result.json"
  benchmark_baseline_file: "benchmarks/baseline.json"
  benchmark_max_regression_percent: 2
  benchmark_stable_environment: true
```

The Composer script must write `benchmark_result_file` using the PHPForge schema. Each PHP matrix result is validated; the regression comparison runs only for the final configured PHP version so one baseline cannot be compared against a different runtime. Keep `benchmark_stable_environment: false` on GitHub-hosted or otherwise noisy runners; comparison then reports a skip instead of failing on unreliable variance.

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
- `check_results` (flat per-check rows with `test`, `dependency_mode`, `php_version`, `status`, `source_job`, `generated_by`)
- `benchmark_results` (per PHP version benchmark rows with `duration_ms`, `delta_ms`, `trend`, `status`, source job metadata, and metric provenance via `benchmark_metric_ns` / `benchmark_metric_source`)
- `rollup` (aggregated status for `code_analysis_prefer_lowest`, `code_analysis_prefer_stable`, `security_analysis`, `benchmark`)
- `benchmark_command`
- `benchmark_job_result`
- `tools` (tool `name`, package, resolved version)

`security-report.svg` renders the same high-level status, per-version matrix check results, rollup quality gates (`Code Lowest`, `Code Stable`, `Security`, `Benchmark`), a benchmark-by-version chart with upgrade/degrade trend labels, and resolved tool versions.

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
      php_versions: '["8.4","8.5"]'
      dependency_versions: '["prefer-lowest","prefer-stable"]'
      run_analysis: true
```

Project with extensions and no SARIF upload:

```yaml
jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    with:
      php_versions: '["8.4","8.5"]'
      php_extensions: "mbstring, intl, pdo_mysql"
      composer_flags: "--ignore-platform-req=ext-redis"
      run_analysis: false
      run_svg_report: true
```

Project with cache/database service containers for integration tests:

```yaml
jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    with:
      php_versions: '["8.4","8.5"]'
      dependency_versions: '["prefer-lowest","prefer-stable"]'
      php_extensions: "mbstring, redis, memcached, pdo_pgsql, pdo_mysql, mongodb"
      enable_redis_service: true
      enable_valkey_service: false
      enable_memcached_service: true
      enable_postgres_service: true
      enable_mysql_service: true
      enable_scylladb_service: true
      enable_elasticsearch_service: true
      enable_mongodb_service: true
      service_db_name: "cachelayer"
      service_db_user: "phpforge"
      service_db_password: "phpforge"
```

Project with extensions and larger analysis limits:

```yaml
jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    permissions:
      security-events: write
      actions: read
      contents: read
    with:
      php_versions: '["8.4","8.5"]'
      dependency_versions: '["prefer-stable"]'
      php_extensions: "mbstring, intl, bcmath, pdo_mysql"
      composer_flags: "--ignore-platform-req=ext-redis"
      phpstan_memory_limit: "2G"
      psalm_threads: "2"
      run_analysis: true
```

For code scanning, project-local PHPStan configs (`phpstan.neon`, then
`phpstan.neon.dist`) and Psalm configs (`psalm.xml`, then `psalm.xml.dist`) are
used when present. The PHPForge repository itself falls back to its root
`resources/` configs; every consuming project falls back to the installed
`vendor/infocyph/phpforge/resources/` configs after Composer installation.

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

Generate community templates for contributing and issue triage:

```bash
composer ic:community
```

Generated files:

- `CONTRIBUTING.md`
- `CODE_OF_CONDUCT.md`
- `SECURITY.md`
- `.github/ISSUE_TEMPLATE/bug_report.yml`
- `.github/ISSUE_TEMPLATE/ci_failure.yml`
- `.github/ISSUE_TEMPLATE/feature_request.yml`
- `.github/ISSUE_TEMPLATE/question.yml`
- `.github/ISSUE_TEMPLATE/docs_improvement.yml`
- `.github/ISSUE_TEMPLATE/config.yml`
- `.github/PULL_REQUEST_TEMPLATE.md`

## Migration Guide

Replace individual QA dependencies with PHPForge.

Before:

```json
"require-dev": {
    "captainhook/captainhook": "^5.29.2",
    "ergebnis/composer-normalize": "^2.52",
    "infocyph/phpprobe": "^0.7",
    "laravel/pint": "^1.30.3",
    "pestphp/pest": "^5.0.2",
    "pestphp/pest-plugin-drift": "^5.0",
    "phpbench/phpbench": "^1.7",
    "phpstan/phpstan": "^2.2.7",
    "psalm/phar": "^6.16.1",
    "rector/rector": "^2.5.9",
    "squizlabs/php_codesniffer": "^4.0.1",
    "symfony/var-dumper": "^8.1.2",
    "tomasvotruba/cognitive-complexity": "^1.2.0"
}
```

After:

```bash
composer require --dev infocyph/phpforge:dev-main@dev
```

PHPForge installs the stable `psalm/phar` distribution. Its isolated
dependency graph prevents Psalm's internal component constraints from colliding
with the PHPUnit version selected by Pest, while preserving the normal Psalm
configuration and security-analysis workflow.

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

SARIF upload is non-blocking when GitHub code scanning or Advanced Security is
unavailable. The Composer audit and local PHPStan/Psalm gates still determine
the analysis job result. Use `run_analysis: false` only to disable that entire
job:

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
composer ic:publish-config phpprobe.json
composer ic:publish-config phpstan.neon.dist
composer ic:publish-config psalm.xml
```

Project config files always take priority over bundled defaults.
For syntax or duplicate detector noise, adjust `phpprobe.json` paths/excludes or duplicate thresholds first.
