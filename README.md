# PHPForge

Shared Composer-powered development tooling for Infocyph PHP projects.

Install it in a project as a dev dependency:

```bash
composer require --dev infocyph/phpforge
```

Composer may ask for first-time approval to run the `infocyph/phpforge` plugin. PHPForge also reports any related plugin permissions that should be enabled:

```json
"allow-plugins": {
    "infocyph/phpforge": true,
    "pestphp/pest-plugin": true,
    "captainhook/captainhook": true
}
```

After Composer activates the plugin, the consuming project can run:

```bash
composer ic:tests
composer ic:ci
composer ic:process
composer ic:benchmark
composer ic:release:guard
composer ic:doctor
```

## Commands

| Command | Purpose |
| --- | --- |
| `ic:tests` | Runs syntax, Pest, Pint check, PHPCS, PHPStan, Psalm security analysis, and Rector dry run. |
| `ic:tests:all` | Alias of `ic:tests`. |
| `ic:tests:details` | Runs each detailed quality check without the parallel Pest shortcut. |
| `ic:test:syntax` | Recursively checks project PHP files while respecting `.gitignore`, Git excludes, and global Git ignore rules. |
| `ic:test:code` | Runs Pest. |
| `ic:test:lint` | Runs Pint in `--test` mode. |
| `ic:test:sniff` | Runs PHP_CodeSniffer. |
| `ic:test:static` | Runs PHPStan with cognitive complexity rules. |
| `ic:test:security` | Runs Psalm security analysis. |
| `ic:test:refactor` | Runs Rector in dry-run mode. |
| `ic:test:bench` | Runs PHPBench aggregate benchmarks. |
| `ic:ci` | Runs the CI quality set. Use `--prefer-lowest` to skip heavyweight static/security analysis. |
| `ic:init` | Copies optional PHPForge project files such as CaptainHook config and the Security & Standards workflow. |
| `ic:doctor` | Shows detected configs, vendor-dir, plugin permissions, and hook status. |
| `ic:process` | Runs Rector, Pint, and PHPCBF fixers. |
| `ic:process:all` | Alias of `ic:process`. |
| `ic:process:lint` | Runs Pint fixes. |
| `ic:process:sniff` | Runs PHPCBF fixes. |
| `ic:process:sniff:fix` | Runs PHPCBF fixes. |
| `ic:process:refactor` | Runs Rector fixes. |
| `ic:benchmark` | Runs PHPBench aggregate benchmarks. |
| `ic:bench:run` | Alias of `ic:benchmark`. |
| `ic:bench:quick` | Runs a quick PHPBench aggregate pass. |
| `ic:bench:chart` | Runs PHPBench chart report. |
| `ic:phpstan:sarif` | Converts PHPStan JSON output to SARIF 2.1.0. |
| `ic:release:audit` | Runs Composer audit guard. |
| `ic:release:guard` | Runs Composer validation, audit, and the full test suite. |
| `ic:hooks` | Installs enabled CaptainHook hooks. |

The bundled CaptainHook pre-commit keeps the original Infocyph sequence with the `ic` namespace: `composer validate --strict`, `composer ic:release:audit`, then `composer ic:tests`.

Useful environment overrides:

| Variable | Default | Purpose |
| --- | --- | --- |
| `IC_PEST_PROCESSES` | `10` | Controls Pest parallel processes for `ic:tests`. |
| `IC_PHPSTAN_MEMORY_LIMIT` | `1G` | Controls PHPStan memory limit. |
| `IC_PSALM_THREADS` | `1` | Controls Psalm thread count. |
| `IC_HOOKS_STRICT` | `1` | Fails Composer when automatic CaptainHook install fails. Set to `0` for best-effort hook installation. |

## Configuration

PHPForge ships default config files for Pint, PHPCS, PHPStan, Psalm, Rector, PHPBench, Pest, PHPUnit, and CaptainHook. If the consuming project has a file with the same name at its root, PHPForge uses the project file instead of the bundled fallback.

This lets a project start with the shared defaults and override only the tools that need project-specific behavior.

Config priority:

| Tool | Project-first config lookup |
| --- | --- |
| Pest | `pest.xml`, then `phpunit.xml`, then bundled `pest.xml` |
| PHPBench | `phpbench.json`, then bundled `phpbench.json` |
| PHPCS / PHPCBF | `phpcs.xml.dist`, then bundled `phpcs.xml.dist` |
| PHPStan | `phpstan.neon.dist`, then bundled `phpstan.neon.dist` |
| Pint | `pint.json`, then bundled `pint.json` |
| Psalm | `psalm.xml`, then bundled `psalm.xml` |
| Rector | `rector.php`, then bundled `rector.php` |

## Migrating an Existing Project

If a project already has the individual QA tools and Composer scripts locally, replace them with PHPForge.

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
},
"scripts": {
    "tests": "@test:all",
    "process": "@process:all",
    "benchmark": "@bench:run"
}
```

After:

```json
"require-dev": {
    "infocyph/phpforge": "^1.0"
}
```

Remove the old local QA scripts from the consuming project:

```json
"scripts": {
}
```

Then run:

```bash
composer require --dev infocyph/phpforge
composer update
composer ic:init
composer ic:doctor
```

Use the PHPForge commands instead:

| Old command | New command |
| --- | --- |
| `composer tests` | `composer ic:tests` |
| `composer test:all` | `composer ic:tests` |
| `composer test:details` | `composer ic:tests:details` |
| `composer test:syntax` | `composer ic:test:syntax` |
| `composer test:code` | `composer ic:test:code` |
| `composer test:lint` | `composer ic:test:lint` |
| `composer test:sniff` | `composer ic:test:sniff` |
| `composer test:static` | `composer ic:test:static` |
| `composer test:security` | `composer ic:test:security` |
| `composer test:refactor` | `composer ic:test:refactor` |
| `composer test:bench` | `composer ic:test:bench` |
| CI command list | `composer ic:ci` |
| `composer process` | `composer ic:process` |
| `composer process:all` | `composer ic:process` |
| `composer process:lint` | `composer ic:process:lint` |
| `composer process:sniff:fix` | `composer ic:process:sniff:fix` |
| `composer process:refactor` | `composer ic:process:refactor` |
| `composer benchmark` | `composer ic:benchmark` |
| `composer bench:run` | `composer ic:benchmark` |
| `composer bench:quick` | `composer ic:bench:quick` |
| `composer bench:chart` | `composer ic:bench:chart` |
| `composer release:audit` | `composer ic:release:audit` |
| `composer release:guard` | `composer ic:release:guard` |

Keep project-specific config files only when the project needs overrides:

```text
captainhook.json
pest.xml
phpbench.json
phpcs.xml.dist
phpstan.neon.dist
phpunit.xml
pint.json
psalm.xml
rector.php
```

If those files are removed, PHPForge falls back to its bundled defaults.

The old helper scripts are no longer needed:

```text
.github/scripts/syntax.php
.github/scripts/composer-audit-guard.php
.github/scripts/phpstan-sarif.php
```

PHPForge provides those behaviors through `ic:test:syntax`, `ic:release:audit`, and `ic:phpstan:sarif`.

Recommended migrated `composer.json` shape:

```json
{
    "require-dev": {
        "infocyph/phpforge": "^1.0"
    },
    "minimum-stability": "stable",
    "prefer-stable": true,
    "config": {
        "sort-packages": true,
        "optimize-autoloader": true,
        "classmap-authoritative": true
    }
}
```

## GitHub Actions

PHPForge includes a converted Security & Standards workflow at:

```text
resources/workflows/security-standards.yml
```

Install it into a consuming project with:

```bash
composer ic:init --workflow
```

It replaces the old local Composer scripts with PHPForge commands:

| Old workflow command | New workflow command |
| --- | --- |
| Multiple `composer test:*` calls | `composer ic:ci` |
| Prefer-lowest CI subset | `composer ic:ci --prefer-lowest` |
| `composer release:audit` | `composer ic:release:audit` |
| `php .github/scripts/phpstan-sarif.php ...` | `composer ic:phpstan:sarif ...` |

For code scanning, the workflow uses a project-local `phpstan.neon.dist` or `psalm.xml` when present. If not present, it falls back to PHPForge's bundled defaults.
