---

name: phpforge-engineering
description: Secure, performant, production-aware PHP engineering using PHPForge composer ic:* workflows, scoped changes, measurable quality checks, and strict complexity control.
-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

# PHPForge Engineering

## Priority Rule

PHPForge workflow guidance has priority over generic PHP engineering guidance.

Prefer `composer ic:*` commands over direct tool execution such as Rector, Pint, PHPCS, PHPStan, Psalm, Pest, PHPBench, or related tools.

Do not edit `vendor/`.

## Core Priorities

Prioritize:

1. Correctness
2. Security
3. Performance
4. Reliability
5. Maintainability

Prefer simple, explicit, measurable solutions.

Avoid speculative architecture, unnecessary abstractions, unnecessary layers, broad rewrites, and behavior changes outside scope.

Choose the option with the best balance of correctness, security, speed, operational safety, and maintainability.

Be skeptical of defaults and unnecessary complexity.

Prefer explicit trade-offs over hidden cost.

When in doubt, choose the simpler, easier-to-measure design.

## Scope Control

Focus on the active task and the smallest correct change set.

Keep changes scoped.

Do not do broad cleanup, refactoring, renaming, file moves, or rewrites without explicit approval.

Do not change behavior outside scope unless required for correctness, security, or stability.

If tooling reveals a broader issue, report it concisely unless immediate correction is necessary.

Do not silently expand scope.

## Startup

Run these first:

```bash
composer ic:doctor
composer ic:list-config
```

Use the output to understand:

* Config resolution
* Plugin permissions
* Hook/workflow state
* Available project configuration
* Runtime metadata when relevant

## Core Flow

Unless the task is read-only, run:

```bash
composer ic:process
```

Then run:

```bash
composer ic:tests:details
```

Fix issues, then re-run the relevant failing command.

Final check should be one of:

```bash
composer ic:tests
composer ic:release:guard
```

Use `composer ic:release:guard` when the task touches:

* Release-sensitive behavior
* Dependency hygiene
* CI/release configuration
* Security-sensitive code
* Package-level quality

If blocked, report:

* The exact failing command
* The key error
* What was already checked
* The recommended next step

## Agent Behavior

Execute the PHPForge flow by default.

Do not ask for routine step confirmation.

Ask only for:

* Destructive or risky actions
* Unclear product decisions
* Missing secrets
* Scope-expanding refactors

Run routine commands directly and report concise results.

For reported clone groups, centralize repeated logic and update all affected call sites.

Review automated changes for correctness.

Keep automated change scope controlled.

## Architecture Guidance

Stay framework-agnostic unless the project clearly requires otherwise.

Follow project conventions unless they conflict with correctness, security, stability, or a clearly better design.

Use SOLID pragmatically.

Prefer composition over inheritance unless inheritance is clearly the better fit.

Avoid unnecessary abstractions and unnecessary layers.

## PHP Standards

Use modern PHP.

Prefer:

* Strict typing
* Clear contracts
* Relevant PHP-FIG standards
* Small methods, functions, and closures
* Clear responsibilities
* Useful docblocks where appropriate
* Explicit behavior over hidden side effects

Avoid:

* Deep nesting
* Hidden side effects
* Mixed responsibilities
* Overly clever code
* Unnecessary allocations
* Unnecessary serialization

Split methods, functions, and closures when complexity gets high.

## Cognitive Complexity Budget

When creating or modifying classes, functions, methods, closures, or dependency trees, keep cognitive complexity as low as practical.

Target limits:

```yaml
cognitive_complexity:
    class: 80
    function: 12
    dependency_tree: 80
```

Stay close to these limits and do not exceed them unless the user explicitly approves an exception.

Interpret “stay close to these limits” as guidance against both excessive complexity and excessive fragmentation.

Do not exceed the limits without explicit approval.
Do not artificially drive complexity far below the limits by splitting cohesive logic into too many tiny functions, classes, methods, or wrappers.

Prefer the smallest number of cohesive units that keeps code clear, testable, and within budget.
Only extract helpers or collaborators when they improve readability, reuse, testability, or separation of responsibility.
Avoid “complexity golfing.”

Treat these as design constraints during implementation.

If a class, function, method, closure, or dependency tree is likely to exceed the limit:

* Split responsibilities.
* Extract focused private methods.
* Introduce small collaborators only when they reduce complexity.
* Prefer early returns over deep nesting.
* Replace branching-heavy logic with clear maps, strategies, or guard clauses where appropriate.
* Keep dependency graphs shallow and explicit.
* Avoid abstractions that only move complexity elsewhere.

Do not reduce cognitive complexity by hiding side effects, weakening type safety, or making behavior harder to trace.

For reported complexity violations, refactor toward the limit and update all affected call sites.

## Performance Guidance

Optimize for low overhead, low latency, and predictable runtime behavior.

Treat these as first-class concerns:

* Hot paths
* I/O cost
* Query cost
* Allocations
* Serialization
* Batching
* Streaming
* Chunking

Avoid unnecessary work in critical paths.

Prefer efficient patterns such as batching, streaming, and chunking where relevant.

Prefer tooling, profiling, benchmarking, and tests over assumptions.

Do not claim performance improvements without measurement or strong technical reasoning.

Use PHPForge benchmark commands when performance validation is relevant.

## Security Guidance

Apply secure-by-default thinking.

Follow OWASP-aligned practices where relevant.

Treat these as mandatory:

* Validation
* Escaping
* Authorization
* Secrets hygiene
* Dependency hygiene
* Safe error handling

Prefer:

* Safe failure
* Reduced blast radius
* Idempotency
* Observability
* Operationally safe behavior

Never expose secrets in logs, exceptions, generated files, fixtures, documentation, or test output.

Use PHPForge security and release commands when security-sensitive changes are involved.

## Reliability and Operations

Keep solutions production-aware.

Consider these where relevant:

* Logs
* Metrics
* Traces
* Health checks
* Config discipline
* Retry behavior
* Idempotency
* Failure isolation
* Operational safety

Follow Twelve-Factor principles where they fit.

## Validation

Prefer validation through PHPForge commands and existing project automation.

Use focused checks for fast feedback when appropriate, then run broader checks before completion.

If validation cannot be completed, clearly state:

* What was checked
* What was not checked
* Why it could not be checked
* The recommended next validation step

## Available Commands

### Diagnostics and Setup

* `composer ic:doctor` - show setup diagnostics including config resolution, plugin permissions, hooks, and workflow checks.
* `composer ic:list-config` - list discovered config files and where each one is resolved from.
* `composer ic:publish-config [file...]` - copy bundled config file or files into the project. Supports `--all` and `--force`.
* `composer ic:init` - interactive project bootstrap for CaptainHook, CI/workflow wrappers, and optional community templates.
* `composer ic:community` - copy generic `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, issue forms/config, and PR template files.
* `composer ic:publish-community-templates` - alias of `composer ic:community`.
* `composer ic:hooks` - install or update enabled CaptainHook hooks.
* `composer ic:clean` - remove known PHPForge output files and cache directories.
* `composer ic:version` - print PHPForge, PHP, and runtime path metadata.
* `composer ic:phpstan:sarif input.json output.sarif` - convert PHPStan JSON to SARIF 2.1.0.

### Quality Checks

* `composer ic:tests` - run the full quality suite.
* `composer ic:tests:all` - alias of `composer ic:tests`.
* `composer ic:tests:parallel` - run syntax first, then bounded-parallel quality checks.
* `composer ic:tests:details` - run the detailed non-parallel shortcut quality checks.
* `composer ic:test:syntax` - PHP syntax scan.
* `composer ic:test:code` - run Pest tests.
* `composer ic:test:lint` - run Pint in check mode.
* `composer ic:test:sniff` - run PHPCS checks.
* `composer ic:test:duplicates` - run duplicate code detection.
* `composer ic:test:probe` - run aggregate PHPProbe checks for syntax, duplicates, API, and comments.
* `composer ic:test:api` - run API snapshot checks via PHPProbe.
* `composer ic:test:comments` - run comment policy checks via PHPProbe.
* `composer ic:test:architecture` - run Deptrac architecture checks.
* `composer ic:test:static` - run PHPStan analysis.
* `composer ic:test:security` - run Psalm security analysis.
* `composer ic:test:refactor` - run Rector dry-run checks.
* `composer ic:test:bench` - run PHPBench aggregate benchmark run.

### Processing and Fixers

* `composer ic:process` - run normalize, Rector, Pint, and PHPCBF fixers.
* `composer ic:process:all` - alias of `composer ic:process`.
* `composer ic:process:refactor` - run Rector fix.
* `composer ic:process:lint` - run Pint fix.
* `composer ic:process:sniff` - run PHPCBF fix.
* `composer ic:process:sniff:fix` - alias of `composer ic:process:sniff`.

### Benchmarks

* `composer ic:benchmark` - run PHPBench aggregate benchmarks.
* `composer ic:bench:run` - alias of `composer ic:benchmark`.
* `composer ic:bench:quick` - run shorter PHPBench benchmark suite.
* `composer ic:bench:chart` - generate PHPBench chart report.

### CI and Release

* `composer ic:ci` - run CI suite using the bounded parallel runner.
* `composer ic:ci --prefer-lowest` - run CI mode for prefer-lowest jobs. Skips heavyweight static and security checks.
* `composer ic:release:audit` - run Composer audit guard.
* `composer ic:release:guard` - run Composer validation, audit, and full quality suite.

## CI Notes

Config precedence:

1. Project root
2. `vendor/infocyph/phpforge/resources`
3. Source `resources/`, only inside the `infocyph/phpforge` repository

Pest parallel is enabled by default for:

* `composer ic:tests`
* `composer ic:ci`

Use this to disable Pest parallel in unstable CI:

```bash
IC_PEST_PARALLEL=0
```

Optional tuning environment variables:

```bash
IC_PEST_PROCESSES
IC_PSALM_THREADS
IC_PHPSTAN_MEMORY_LIMIT
```

Reusable workflow optional service inputs:

* `enable_redis_service`
* `enable_valkey_service`
* `enable_memcached_service`
* `enable_postgres_service`
* `enable_mysql_service`
* `enable_scylladb_service`
* `enable_elasticsearch_service`
* `enable_mongodb_service`

Reusable workflow shared credentials:

* `service_db_name`
* `service_db_user`
* `service_db_password`

Reusable workflow strict skip gate:

```yaml
fail_on_skipped_tests: true
```

This passes `--fail-on-skipped` to Pest in CI.

Extension requirements by service:

* Redis and Valkey require the `redis` extension.
* Memcached requires the `memcached` extension.
* PostgreSQL requires the `pdo_pgsql` extension.
* MySQL requires the `pdo_mysql` extension.
* MongoDB requires the `mongodb` extension.

Service environment variables exported by workflow:

Redis:

```bash
IC_REDIS_HOST
IC_REDIS_PORT
IC_REDIS_PASSWORD
```

Valkey:

```bash
IC_VALKEY_HOST
IC_VALKEY_PORT
IC_VALKEY_PASSWORD
```

ScyllaDB Alternator:

```bash
IC_SCYLLADB_HOST
IC_SCYLLADB_PORT
IC_SCYLLADB_ENDPOINT
IC_SCYLLADB_REGION
IC_SCYLLADB_ACCESS_KEY_ID
IC_SCYLLADB_SECRET_ACCESS_KEY
```

## Default Behavior

When executing tasks under this skill:

1. Stay focused on the active task.
2. Make the smallest correct change.
3. Prefer explicit, measurable solutions.
4. Keep PHP code modern, secure, and efficient.
5. Follow project conventions unless there is a strong reason not to.
6. Run `composer ic:doctor` and `composer ic:list-config` first.
7. For implementation work, run `composer ic:process`, then `composer ic:tests:details`.
8. Run `composer ic:tests` or `composer ic:release:guard` before completion when appropriate.
9. Keep class, function, and dependency-tree cognitive complexity within configured limits.
10. Report broader issues instead of silently expanding scope.
