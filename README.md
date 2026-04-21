# PHPForge

Shared Composer-powered QA, refactoring, benchmark, release, hook, and CI tooling for Infocyph PHP projects.

## Install

```bash
composer require --dev infocyph/phpforge
```

Composer may ask for first-time approval before it can run the PHPForge plugin. If needed, enable the related plugins:

```bash
composer config allow-plugins.infocyph/phpforge true
composer config allow-plugins.pestphp/pest-plugin true
composer config allow-plugins.captainhook/captainhook true
```

Then inspect the project setup:

```bash
composer ic:doctor
```

## Commands

| Command | Purpose |
| --- | --- |
| `ic:tests` | Runs the full project quality suite: syntax, Pest, Pint, PHPCS, PHPStan, Psalm security analysis, and Rector dry run. |
| `ic:tests:all` | Alias of `ic:tests`. |
| `ic:tests:details` | Runs each detailed quality check without the parallel Pest shortcut. |
| `ic:test:syntax` | Checks project PHP files while respecting `.gitignore`, Git excludes, and global Git ignore rules. |
| `ic:test:code` | Runs Pest. |
| `ic:test:lint` | Runs Pint in `--test` mode. |
| `ic:test:sniff` | Runs PHP_CodeSniffer. |
| `ic:test:static` | Runs PHPStan with cognitive complexity rules. |
| `ic:test:security` | Runs Psalm security analysis. |
| `ic:test:refactor` | Runs Rector in dry-run mode. |
| `ic:test:bench` | Runs PHPBench aggregate benchmarks. |
| `ic:ci` | Runs syntax, Pest, Pint, PHPCS, Rector, and optionally PHPStan/Psalm. |
| `ic:ci --prefer-lowest` | Runs the CI set without PHPStan/Psalm for prefer-lowest jobs. |
| `ic:process` | Runs Rector, Pint, and PHPCBF fixers. |
| `ic:process:lint` | Runs Pint fixes. |
| `ic:process:sniff` | Runs PHPCBF fixes. |
| `ic:process:sniff:fix` | Runs PHPCBF fixes. |
| `ic:process:refactor` | Runs Rector fixes. |
| `ic:benchmark` | Runs PHPBench aggregate benchmarks. |
| `ic:bench:run` | Alias of `ic:benchmark`. |
| `ic:bench:quick` | Runs a quick PHPBench aggregate pass. |
| `ic:bench:chart` | Runs PHPBench chart report. |
| `ic:phpstan:sarif` | Converts PHPStan JSON output to SARIF 2.1.0. |
| `ic:release:audit` | Runs Composer audit guard. Advisories fail; abandoned packages warn. |
| `ic:release:guard` | Runs Composer validation, audit, and the full test suite. |
| `ic:hooks` | Installs enabled CaptainHook hooks. |
| `ic:init` | Copies optional project files such as `captainhook.json` and workflow wrappers. |
| `ic:doctor` | Shows detected configs, vendor-dir, plugin permissions, and hook status. |

## Configuration

Project config files always win over PHPForge bundled defaults.

| Tool | Lookup order |
| --- | --- |
| Pest | `pest.xml`, then `phpunit.xml`, then bundled `pest.xml` |
| PHPBench | `phpbench.json`, then bundled `phpbench.json` |
| PHPCS / PHPCBF | `phpcs.xml.dist`, then bundled `phpcs.xml.dist` |
| PHPStan | `phpstan.neon.dist`, then bundled `phpstan.neon.dist` |
| Pint | `pint.json`, then bundled `pint.json` |
| Psalm | `psalm.xml`, then bundled `psalm.xml` |
| Rector | `rector.php`, then bundled `rector.php` |

Useful environment overrides:

| Variable | Default | Purpose |
| --- | --- | --- |
| `IC_PEST_PROCESSES` | `10` | Controls Pest parallel processes for `ic:tests`. |
| `IC_PHPSTAN_MEMORY_LIMIT` | `1G` | Controls PHPStan memory limit. |
| `IC_PSALM_THREADS` | `1` | Controls Psalm thread count. |
| `IC_HOOKS_STRICT` | `1` | Fails Composer when automatic CaptainHook install fails. Set to `0` for best-effort hook installation. |

## Hooks

Install the default CaptainHook config:

```bash
composer ic:init --captainhook
composer ic:hooks
```

The bundled pre-commit sequence is:

```bash
composer validate --strict
composer ic:release:audit
composer ic:tests
```

This PHPForge repository also defines local `ic:*` Composer scripts because Composer does not load the root package as its own plugin. Consuming projects use the plugin-provided commands.

## Migrating

Replace individual QA dependencies with PHPForge.

Before:

```json
"require-dev": {
    "captainhook/captainhook": "^5.29.2",
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

Remove old local QA scripts such as `test:*`, `process:*`, `bench:*`, `tests`, `process`, `benchmark`, `release:audit`, and `release:guard`.

Command mapping:

| Old command | New command |
| --- | --- |
| `composer tests` / `composer test:all` | `composer ic:tests` |
| `composer test:details` | `composer ic:tests:details` |
| `composer test:syntax` | `composer ic:test:syntax` |
| `composer test:code` | `composer ic:test:code` |
| `composer test:lint` | `composer ic:test:lint` |
| `composer test:sniff` | `composer ic:test:sniff` |
| `composer test:static` | `composer ic:test:static` |
| `composer test:security` | `composer ic:test:security` |
| `composer test:refactor` | `composer ic:test:refactor` |
| `composer process` / `composer process:all` | `composer ic:process` |
| `composer process:lint` | `composer ic:process:lint` |
| `composer process:sniff:fix` | `composer ic:process:sniff:fix` |
| `composer process:refactor` | `composer ic:process:refactor` |
| `composer benchmark` / `composer bench:run` | `composer ic:benchmark` |
| `composer bench:quick` | `composer ic:bench:quick` |
| `composer bench:chart` | `composer ic:bench:chart` |
| `composer release:audit` | `composer ic:release:audit` |
| `composer release:guard` | `composer ic:release:guard` |

Old helper scripts are no longer needed:

```text
.github/scripts/syntax.php
.github/scripts/composer-audit-guard.php
.github/scripts/phpstan-sarif.php
```

PHPForge provides those through `ic:test:syntax`, `ic:release:audit`, and `ic:phpstan:sarif`.

## GitHub Actions

PHPForge publishes a reusable workflow:

```yaml
uses: infocyph/phpforge/.github/workflows/security-standards.yml@v1
```

Install a wrapper workflow into a consuming project:

```bash
composer ic:init --workflow --workflow-ref=v1
```

Generated wrapper shape:

```yaml
name: "Security & Standards"

on:
  push:
    branches: [ "main", "master" ]
  pull_request:
    branches: [ "main", "master", "develop", "development" ]

jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@v1
    permissions:
      security-events: write
      actions: read
      contents: read
    with:
      php_versions: '["8.2","8.3","8.4","8.5"]'
      dependency_versions: '["prefer-lowest","prefer-stable"]'
      run_analysis: true
```

Workflow inputs:

| Input | Default | Purpose |
| --- | --- | --- |
| `php_versions` | `["8.2","8.3","8.4","8.5"]` | PHP matrix as a JSON array string. |
| `dependency_versions` | `["prefer-lowest","prefer-stable"]` | Composer dependency modes as a JSON array string. |
| `run_analysis` | `true` | Runs SARIF upload jobs for PHPStan and Psalm. Set to `false` for CI-only runs. |

For code scanning, project-local `phpstan.neon.dist` and `psalm.xml` are used when present; otherwise the workflow falls back to PHPForge defaults.
