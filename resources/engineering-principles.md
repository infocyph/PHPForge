# PHPForge Engineering Instructions

## Engineering Scope And Decision Rules

### Core Policy And Change Discipline

- Stay framework-agnostic unless the project clearly requires otherwise.
- Treat correctness, security, data integrity and operational stability as non-negotiable constraints.
- The primary performance objective is the highest sustainable number of successful requests per minute (`RPM`) on production-equivalent infrastructure.
- Among correct, secure and stable implementations, choose the implementation with the highest measured sustained throughput.
- Optimize the complete execution path rather than isolated syntax, minimum resource usage or architectural fashion.
- Prefer simple, explicit and measurable solutions.
- Avoid speculative architecture, unnecessary abstractions and unnecessary layers.
- Follow project conventions unless they conflict with correctness, security, throughput or a clearly better design.
- Use modern PHP, strict typing and clear contracts.
- Apply accepted PHP-FIG PSRs at genuine interoperability boundaries where providers and consumers benefit from a shared contract.
- Apply the project's declared major version of relevant PHP Evolving Recommendations (`PERs`) where their guidance fits.
- Do not introduce a PSR interface, implementation package, adapter or conversion merely because a standard exists.
- Keep interoperability adaptation at boundaries and preserve the simplest efficient internal representation.
- When a PSR-compliant and project-specific design provide equivalent behavior and sustained RPM, prefer the interoperable PSR contract.
- Apply `SOLID` pragmatically. Prefer composition over inheritance only when it creates a real contract, substitution boundary or reduction in total complexity.
- Be skeptical of defaults, conventions and abstractions that introduce hidden runtime or operational costs.
- When sustained throughput is practically equivalent, choose the implementation with lower complexity, lower operating cost and more predictable behavior.
- When uncertain, choose the simpler design that is easier to benchmark, profile, operate and change.
- Use deterministic local tooling for deterministic work; reserve agent context and reasoning for decisions that actually require judgment.
- Optimize agent token usage by eliminating redundant context, repeated inspection, unnecessary output and avoidable tool work; never trade correctness, verification depth or solution quality for fewer tokens.
- Interpret specific rules as refinements of general rules.
- When rules appear to conflict, apply them in this order:
  - correctness, security, authorization, data integrity and operational stability,
  - explicit public, protocol and interoperability contracts,
  - the more specific domain or boundary rule,
  - the highest sustainable successful RPM within defined budgets,
  - and then lower total complexity, operating cost and maintenance burden.
- Do not use a general performance or simplification rule to override a stricter security, compatibility, quality-gate or protocol requirement.
- When two applicable blocking requirements cannot both be satisfied, do not silently choose one or claim completion.
- Redesign the change or report the conflict for an explicit project decision.
- A change is acceptable only when every applicable blocking constraint is satisfied.

- Focus on the active task and implement the smallest correct change set.
- Do not perform broad cleanup, refactoring, renaming, file moves, dependency changes or rewrites without explicit approval.
- Do not change behavior outside the requested scope unless required for correctness, security, compatibility or stability.
- Preserve public contracts unless a breaking change is explicitly approved.
- Do not introduce infrastructure, dependencies, patterns or abstractions for hypothetical future requirements.
- Separate required changes from optional improvements.
- Make meaningful trade-offs explicit.
- Do not hide unrelated refactoring inside feature or bug-fix work.

### Universal Applicability

- Apply these instructions to all first-party PHP code, including:
  - applications,
  - frameworks,
  - libraries,
  - SDKs,
  - packages,
  - command-line tools,
  - workers,
  - database abstractions,
  - query builders,
  - cache clients,
  - authentication components,
  - OTP and cryptographic libraries,
  - serializers,
  - middleware,
  - adapters,
  - and infrastructure utilities.
- Assume any reusable PHP component may eventually execute inside a high-volume request-response lifecycle.
- Design every package so it contributes the least practical overhead to the host application's sustained successful RPM.
- Do not treat code as performance-insensitive merely because it does not directly serve HTTP requests.
- A component may execute:
  - once during bootstrap,
  - once per request,
  - several times per request,
  - once per database row,
  - inside a hot loop,
  - inside a worker,
  - or inside a persistent application server.
- Evaluate cost according to expected call frequency and placement in the complete execution path.
- For reusable libraries, isolated calls per second are a supporting metric; the primary metric remains the effect on representative host-application RPM.
- Do not claim application-level throughput from a component-only microbenchmark.
- Avoid framework-specific assumptions unless the package explicitly targets that framework.
- Keep reusable code safe for normal PHP-FPM execution and persistent-worker runtimes where practical.
- Do not retain request-specific, tenant-specific or user-specific state in static or long-lived objects unless the lifecycle is explicit and safely reset.
- Keep package bootstrap deterministic, minimal and free from unnecessary I/O.
- Avoid automatic filesystem scanning, network access, environment parsing, reflection discovery or service registration during package loading.
- Do not perform work at file-include time beyond declarations and immutable compile-time-safe setup.
- Defer optional work until it is actually required.
- Keep dependencies minimal and justify each dependency by substantial functional or measured throughput value.
- Preserve a short, direct common path for the most frequently used operations.
- Keep optional diagnostics, tracing and logging disabled by default unless required by the contract.
- Expose observability hooks without imposing expensive work on users who do not enable them.
- Benchmark realistic repeated invocation for packages that may run inside loops or row processing.
- Benchmark cold and warm loading for packages used during bootstrap.
- Soak-test repeated execution and state reset for packages intended for persistent workers.

## Performance Objective, Measurement And Acceptance

### Sustained Throughput Objective

- The primary performance metric is sustained successful requests per minute (`RPM`).
- Use successful requests per second (`RPS`) when convenient and convert consistently:

  `RPM = RPS × 60`

- Count only complete, correct and valid responses as successful throughput.
- Do not count failed, timed-out, partial, invalid, incorrectly authorized or incorrectly cached responses as successful throughput.
- Optimize for the highest stable RPM the complete system can sustain on production-equivalent hardware.
- Resource efficiency, minimum latency for one isolated request, minimum allocation count, minimum file count and architectural elegance are secondary to sustained successful RPM.
- Accept additional CPU, memory, caching, precomputation, indexing, duplication, persistent workers or process capacity when they materially increase sustained successful RPM.
- Do not reject a higher-throughput implementation merely because it uses moderately more CPU or memory.
- Keep every resource-for-throughput trade bounded by explicit limits such as:
  - request memory,
  - worker memory,
  - cache size,
  - process count,
  - connection count,
  - concurrency,
  - queue depth,
  - payload size,
  - execution timeout,
  - database capacity,
  - and downstream rate limits.
- Do not accept a single-request latency improvement that lowers total sustained RPM.
- Do not accept a short burst result when queues, memory, errors, timeouts or downstream pressure continue growing.
- Sustainable throughput requires:
  - stable queue depth,
  - bounded memory,
  - bounded connection usage,
  - acceptable error and timeout rates,
  - correct responses,
  - and no progressive degradation during the benchmark period.
- Prefer the implementation that produces the highest median sustained successful RPM across repeated representative runs.
- When two implementations provide practically equivalent RPM, choose the one with lower complexity, lower operating cost and safer operations.
- Do not require a dedicated benchmark for every trivial, semantically equivalent reduction in work.
- Require representative measurement for architectural changes, hot paths, repeated operations and changes likely to affect request throughput.

### Application And Library Throughput

- Optimize library APIs for repeated use, not only one isolated invocation.
- Keep common operations allocation-conscious and avoid unnecessary object graphs, wrappers and conversions.
- Avoid hidden initialization on the first business call when initialization can be performed explicitly or amortized safely.
- Reuse immutable validated configuration instead of reparsing or renormalizing it on every call.
- Do not cache caller-specific, tenant-specific, secret or mutable values across requests unless safely scoped and explicitly supported.
- Accept deploy-varying configuration and attached resources from the caller instead of discovering them implicitly, unless discovery is the package's explicit responsibility.
- Benchmark:
  - first call,
  - warm repeated calls,
  - valid input,
  - invalid input,
  - failure paths,
  - large input,
  - and repeated calls inside one request.
- Report both isolated component throughput and representative end-to-end request throughput.
- Reject an isolated optimization when integration overhead lowers complete application RPM.

### Performance Measurement

- Measure before and after every meaningful performance-sensitive change.
- Establish a reproducible production-equivalent RPM baseline before optimization.
- The primary result is sustained successful `RPM`; also record `RPS`.
- Measure complete end-to-end requests rather than only isolated functions.
- Confirm every benchmark candidate returns equivalent correct output.
- Run benchmarks at several concurrency levels to produce a throughput curve.
- Identify:
  - the concurrency where throughput begins to rise,
  - the concurrency where peak sustained RPM is reached,
  - and the point where queueing, errors or timeouts begin to dominate.
- Benchmark long enough to reach steady state and expose:
  - worker saturation,
  - CPU throttling,
  - connection exhaustion,
  - cache churn,
  - memory growth,
  - allocator pressure,
  - and queue accumulation.
- Warm OPcache, Composer metadata, generated configuration and relevant application caches before measuring warm-state production throughput.
- Measure cold-start behavior separately when restarts, deployments or autoscaling are operationally frequent.
- Measure lazy first-use cost separately from warm memoized or reused execution.
- Include representative optional-feature usage so eager and lazy strategies are compared against the real request mix.
- Use multiple runs and compare median sustained successful RPM.
- Record variance and reject conclusions when the difference is within normal measurement noise.
- Confirm the load generator, client CPU and network path are not the bottleneck.
- Distribute load generation across multiple clients when one client cannot saturate the server reliably.
- Record at minimum:
  - successful `RPS`,
  - successful `RPM`,
  - total completed requests,
  - failed requests,
  - timeout rate,
  - response-validation failures,
  - benchmark duration,
  - concurrency,
  - `p50`, `p95` and `p99` latency,
  - CPU utilization,
  - peak and steady-state memory,
  - PHP worker utilization,
  - queue depth,
  - database connections,
  - query count,
  - cache hit rate,
  - and external-service calls.
- Measure the complete production-like stack, including:
  - web server,
  - PHP runtime,
  - OPcache,
  - bootstrap,
  - application or library code,
  - database,
  - cache,
  - serialization,
  - authentication,
  - authorization,
  - and required external dependencies.
- Use microbenchmarks only to investigate a known hot operation.
- Do not use a microbenchmark as proof that complete request RPM improved.
- Control PHP version, extensions, OPcache settings, Composer mode, hardware, operating system, database state and datasets.
- Disable Xdebug and non-production debugging extensions unless measuring their intentional production use.
- Record benchmark commands, configuration and environment details so results can be reproduced.
- Test scaling behavior across representative input sizes and data distributions.
- Include expected failure, miss and retry paths when they materially affect production traffic.
- Do not claim a throughput improvement from a single run, short burst or invalid response.

**Benchmark Diagnostics**

- When several benchmark phases run in one PHP process, use `memory_reset_peak_usage()` where supported to isolate phase-specific peak-memory measurements.
- Do not call memory-measurement functions in ordinary hot request paths unless production telemetry explicitly requires them.
- On supported CLI runtimes, capture non-default INI settings with `php --ini=diff` as part of benchmark and incident evidence.
- Include extension versions and relevant native-client versions in benchmark records.

### Required Benchmark Coverage

- Maintain separate production-equivalent benchmarks for workload classes relevant to the project.
- Include, where applicable:
  - minimal bootstrap and routing,
  - plain or minimal response,
  - JSON serialization,
  - cached read,
  - cache miss and fill,
  - single database read,
  - multi-row database read,
  - database write transaction,
  - authenticated request,
  - authorization-heavy request,
  - validation-heavy request,
  - non-cryptographic hashing,
  - cryptographic digest generation,
  - HMAC generation and verification,
  - OTP generation,
  - OTP verification,
  - external-service request,
  - large response or export,
  - file upload,
  - repeated library calls inside one request,
  - lazy first-use initialization,
  - eager startup initialization,
  - batched or prefetched relation loading,
  - lazy streaming or cursor consumption,
  - and background-job processing.
- Do not extrapolate application RPM from a Hello World benchmark.
- Use minimal-response benchmarks to identify bootstrap, routing and framework overhead.
- Use representative business-route benchmarks to identify database, cache, serialization, authentication, validation and integration costs.
- Weight benchmark scenarios according to actual or expected traffic distribution.
- Optimize scenarios that dominate total production request volume.
- Keep benchmark fixtures deterministic and large enough to expose realistic query, allocation and memory behavior.

### Optimization And Acceptance Rules

- Optimize algorithms, query count, I/O, allocations, copying, serialization and data movement before cosmetic syntax.
- Apply low-risk, semantically equivalent reductions in work without requiring a benchmark for every occurrence.
- Prefer `isset()` over `array_key_exists()` when null and missing are intentionally equivalent.
- Prefer direct access and one-pass processing over callback-heavy pipelines that create avoidable intermediate arrays on hot or large datasets.
- Prefer reuse of an already computed value when recomputation is non-trivial and the cached value has a clear bounded lifetime.
- Do not add caching, indexes or precomputation when their construction, allocation and invalidation cost is likely to exceed reuse.
- Treat generic PHP microbenchmarks as evidence, not universal law. Confirm important decisions on the project's PHP version and representative workload.
- Do not change semantics merely to use a faster-looking construct.
- Do not assume meaningful gains from trivial stylistic choices such as:
  - pre-increment versus post-increment when the result is unused,
  - single quotes versus double quotes without interpolation,
  - `echo` versus `print`,
  - or formatting differences.
- Do consider function-call, callback, allocation and intermediate-array overhead in code executed millions of times or across very large datasets.
- Avoid clever bit-level, branchless or manual-inlining tricks unless the path is demonstrably hot and the result is clearer or measurably faster.
- Never weaken validation, authorization, escaping, type safety, error handling or cryptographic security for speed.
- Avoid repeated defensive checks after a value has already been validated and converted at a trusted boundary, unless the contract permits mutation or another trust boundary is crossed.
- Document non-obvious performance code with the reason, evidence and constraints that justify it.
- Revert an optimization when its measured benefit is insignificant or its complexity creates a greater maintenance or correctness risk.

- Treat these instructions as engineering guardrails; only representative measurements prove that an implementation is performant.
- The primary acceptance metric is median sustained successful requests per minute (`RPM`) across repeated production-equivalent runs.
- Count only complete, correct, authorized and valid responses as successful throughput.
- Define measurable performance budgets for each important:
  - request path,
  - command,
  - worker,
  - batch workflow,
  - background job,
  - and reusable library operation.
- Do not use one universal RPM, latency, memory, query-count or throughput target for unrelated workloads.
- Define workload-specific limits such as:

```yaml
throughput_budget:
    successful_rpm_min: <baseline-or-target>
    max_rpm_regression_percent: 2
    max_error_rate_percent: <project-limit>
    max_timeout_rate_percent: <project-limit>
    max_p99_latency_ms: <safety-limit>
    max_peak_memory_mb: <capacity-limit>
    max_queue_growth: 0
```

- Establish a production-representative baseline before changing a performance-sensitive path.
- Validate performance against representative:
  - data volume,
  - data distribution,
  - concurrency,
  - request mix,
  - repeated call frequency,
  - dependency latency,
  - cache-hit and cache-miss states,
  - database state,
  - success paths,
  - failure paths,
  - and retry behavior.
- Measure complete end-to-end behavior in addition to isolated functions or library calls.
- For reusable libraries, measure both:
  - isolated operation throughput,
  - and the effect on representative host-application RPM.
- Do not claim application-level throughput from a component-only microbenchmark.
- Track at minimum where relevant:
  - successful `RPS`,
  - successful `RPM`,
  - total completed requests,
  - failed requests,
  - error rate,
  - timeout rate,
  - response-validation failures,
  - benchmark duration,
  - concurrency,
  - `p50`, `p95` and `p99` latency,
  - CPU utilization,
  - peak and steady-state memory,
  - allocation or memory-growth rate,
  - worker utilization,
  - queue depth and queue growth,
  - query count,
  - rows examined and returned,
  - lock duration,
  - transaction duration,
  - database connection usage,
  - external calls and dependency latency,
  - and cache hit rate.
- Add automated performance-regression checks only where the workload and environment are stable enough to produce a reliable signal.
- Define an acceptable regression tolerance for each benchmark.
- For stable, business-critical benchmarks, use a default maximum tolerated median RPM regression of `2%` unless measured variance requires a different threshold.
- Do not fail builds for differences that remain within established benchmark variance.
- Treat a material sustained RPM regression as a blocking defect unless explicitly approved.
- Do not approve a change merely because it reduces:
  - CPU,
  - memory,
  - allocations,
  - file count,
  - object count,
  - or single-request latency
  when sustained successful RPM decreases.
- Do not approve higher RPM when:
  - output correctness differs,
  - authorization or validation is bypassed,
  - data integrity changes,
  - security protections are reduced,
  - error or timeout rates exceed their budgets,
  - queue depth grows continuously,
  - memory grows without bound,
  - workers or connections are exhausted,
  - or downstream systems become unstable.
- Treat `p95` and `p99` latency as safety constraints rather than the primary optimization target.
- A latency increase may be accepted when sustained RPM materially improves and latency remains within the defined safety budget.
- Do not accept a short-burst throughput increase when steady-state RPM is lower or the system progressively degrades.
- Use static analysis to enforce structural rules, but never treat static-analysis success as performance validation.
- Use query-plan analysis for important database paths.
- Use load testing for concurrency-sensitive and capacity-sensitive paths.
- Use soak testing for:
  - persistent workers,
  - consumers,
  - daemons,
  - repeated library execution,
  - and processes where memory growth or resource leakage is possible.
- Compare cold-start and warm-runtime performance separately.
- Use warm-state sustained RPM as the primary production metric unless cold starts are operationally frequent.
- Test with the same:
  - PHP major and minor version,
  - extensions,
  - Composer mode,
  - OPcache configuration,
  - runtime model,
  - operating system,
  - hardware class,
  - database configuration,
  - cache configuration,
  - and relevant infrastructure
  used in production.
- Confirm that the load generator, client CPU and network path are not the benchmark bottleneck.
- Record benchmark and load-test commands, configuration and environment details so results can be reproduced.
- Verify critical throughput and stability metrics after deployment through production telemetry or a controlled production-like canary.
- Define rollback or mitigation criteria for:
  - RPM regression,
  - error-rate increase,
  - timeout increase,
  - unacceptable `p99` latency,
  - unbounded queue growth,
  - memory growth,
  - worker exhaustion,
  - database saturation,
  - cache instability,
  - and downstream overload.
- Review performance budgets as traffic, call frequency, data volume, hardware, runtime and business requirements change.
- Optimize the dominant measured bottleneck rather than attempting to apply every possible optimization.
- Require explicit review before adding the following across hot or frequently called paths:
  - middleware,
  - wrappers,
  - object-conversion layers,
  - reflection,
  - dynamic discovery,
  - repeated validation,
  - synchronous logging,
  - additional database calls,
  - additional serialization passes,
  - broad `unset()` calls,
  - helper dispatch,
  - or additional bootstrap work.
- Do not approve automated bulk transformations affecting hot paths without representative before-and-after RPM measurements.
- Treat large elapsed-time or RPM regressions as blocking defects even when formatting, static analysis, style checks and unit tests pass.
- Prefer the highest justified sustainable successful RPM over theoretical, short-lived or benchmark-only speed.
- Treat resource growth as acceptable when it produces a material RPM improvement and remains within explicit stability and capacity budgets.
- Never trade correctness, security, authorization, data integrity or operational stability for benchmark throughput.

## Runtime Compatibility, Standards And Public Contracts

### Runtime Support And Upgrade Policy

- Treat the selected PHP version, enabled extensions, build type and important INI settings as part of the executable contract.
- Run production workloads on an actively supported PHP branch and keep current with compatible patch releases.
- Use an unsupported branch only through a documented exception with compensating controls and an upgrade deadline.
- Declare the minimum supported PHP version and required `ext-*` extensions in Composer where practical.
- Test against real supported runtimes; do not rely only on Composer platform emulation.
- Keep `config.platform.php`, when used, aligned with production and verify the actual runtime separately.
- For reusable packages:
  - maintain a supported-version matrix,
  - test the lowest supported version,
  - test the primary production version,
  - and test the next intended upgrade target where reliable.
- Review migration guides, deprecations, removed features, extension changes and default-value changes before each minor-version upgrade.
- Run tests with `E_ALL` and capture deprecations in CI or a dedicated compatibility job.
- Treat deprecations as migration work rather than production noise to suppress indefinitely.
- Resolve feature availability once during build, startup or bootstrap.
- Avoid repeated `PHP_VERSION_ID`, `version_compare()`, `function_exists()`, `class_exists()` or extension checks in hot paths.
- Select the compatible implementation once and reuse it.
- Do not add a user-land polyfill when the minimum supported runtime already provides the native API.
- When a polyfill is required:
  - isolate it from business code,
  - preserve native semantics,
  - avoid future global-symbol conflicts,
  - and benchmark it when used in a hot path.
- Treat a PHP runtime upgrade as a performance experiment:
  - rerun representative RPM benchmarks,
  - compare memory use and worker density,
  - verify OPcache and JIT behavior,
  - and verify extension, database and serialization compatibility.
- Record the exact PHP version, extension versions, build type and relevant INI values with every benchmark result.

### Public API And Language Compatibility

- Treat public function and method parameter names as compatibility-sensitive because callers may use named arguments.
- Do not rename public parameters in a backward-compatible release unless named-argument compatibility is explicitly excluded.
- Declare properties explicitly and do not rely on dynamic properties.
- Use `stdClass`, documented associative structures or `#[\AllowDynamicProperties]` only when open-ended fields are intentional.
- Prefer explicit nullable types such as `?Type` or `Type|null`; do not rely on implicitly nullable parameters.
- Use typed class constants when supported and when they strengthen a public or inheritance contract.
- Use `#[\Override]` where supported to detect accidental overriding-contract drift.
- Use `#[\Deprecated]` for intentionally deprecated public APIs where supported.
- Pair each deprecation with:
  - a replacement,
  - a migration message,
  - a version or removal timeline,
  - and compatibility tests.
- Use `#[\NoDiscard]` when ignoring a return value is likely to hide a correctness failure.
- Apply `#[\NoDiscard]` to the concrete callable that is actually invoked.
- Use `#[\SensitiveParameter]` on secret-bearing parameters where supported.
- Do not treat attribute-based redaction as a replacement for safe logging and exception handling.
- Use readonly classes, readonly properties, asymmetric visibility and property hooks only when they enforce a useful contract.
- Do not introduce property hooks, lazy objects, attributes or reflection as presumed performance optimizations.

### Coding Style And File Basics

- Follow PSR-1 for basic code interoperability.
- For new or intentionally reformatted code, follow the project-declared major version of PER Coding Style.
- Prefer the latest approved PER Coding Style major version supported by the project's PHP baseline.
- Keep an established repository style unless a style migration is explicitly approved.
- Do not create a repository-wide formatting diff as part of an unrelated feature or bug fix.
- Enforce coding style with a deterministic formatter and CI rather than subjective review.
- Pin the selected coding-style major version in project documentation or tooling.
- Use UTF-8 without a byte-order mark.
- Use Unix `LF` line endings.
- End PHP files with a single newline.
- Omit the closing `?>` tag from PHP-only files.
- A PHP file should normally either declare symbols or perform side effects, but not both.
- Keep bootstrap and executable side effects in explicit entry-point or configuration files.
- Use one statement per line.
- Use four spaces for indentation and do not use tabs for indentation.
- Avoid trailing whitespace.
- Apply style rules for newer syntax only when that syntax exists in the supported PHP versions.
- Do not use deprecated PSR-2 as the coding-style target for new code.
- Retain PSR-12 only where an established project or toolchain still requires it; prefer PER Coding Style for current PHP syntax.

### Comments, PHPDoc And Documentation Examples

- Treat comments and PHPDoc as maintained engineering contracts, not decoration.
- Write comments correctly when the code is introduced; do not defer comment quality until release cleanup or CI remediation.
- Explain intent, invariants, constraints, trade-offs, units, lifecycle rules or non-obvious behavior that the code and native types cannot communicate clearly.
- Do not narrate straightforward statements, repeat symbol names or restate native parameter and return types.
- Keep every comment accurate after behavior changes. Remove or update stale comments in the same change that makes them stale.
- Prefer clearer code, names and types over comments that compensate for avoidable complexity.
- Use `//` for a short local explanation and a proper `/** ... */` PHPDoc block for a documented symbol, structured contract or documentation example.
- Format PHPDoc conventionally: begin with `/**`, prefix content lines with `*`, and keep prose and tags parseable by standard PHPDoc tooling.
- Do not place decorative prefixes such as framework-style `|` rulers inside PHPDoc. Decorative block comments may be used only when they contain prose that cannot be mistaken for disabled PHP.
- Add PHPDoc types only when they refine the native contract, such as array shapes, generics, non-empty values, callable signatures or conditional relationships.
- Keep PHPDoc signatures synchronized with the implementation, including every documented parameter, return, thrown exception, template and property type.
- Do not use an invalid, vague or broader PHPDoc type merely to silence static analysis.
- Mark documentation code with a standalone, recognized label immediately before the sample: `Example:`, `Examples:`, `Usage:`, `Snippet:` or `Code sample:`.
- Keep code-like syntax out of ordinary prose when it can be expressed clearly as prose. Put executable-looking arrays, calls, class constants and mappings under a labeled example.
- A configuration documentation block that contains code-like examples must be a properly formatted PHPDoc example, not commented-out executable code.
- For every public configuration key, document:
  - its purpose and effective type,
  - its default and null, empty or disabled semantics,
  - every predefined allowed value,
  - a representative value when the domain is open-ended,
  - units, ranges and boundary behavior,
  - applicable drivers or runtime modes,
  - security or operational consequences,
  - and whether it is validated, normalized, cached or read lazily.
- Keep configuration documentation aligned with the actual effective surface accepted by the owning library. Do not advertise ignored, unsupported or pass-through keys as supported behavior.
- Prefer one complete example over several partial examples that leave required relationships ambiguous.
- Ensure examples use supported classes, keys, values and public APIs and remain executable after contract changes.

Use this form for code-bearing configuration documentation:

```php
/**
 * Shared lock configuration.
 *
 * `driver` accepts `file|redis|memcached|pdo`. A null value leaves locking
 * disabled. `lease` is a positive number of seconds.
 *
 * Example:
 * [
 *     'driver' => 'redis',
 *     'lease' => 30.0,
 * ]
 */
'lock' => [],
```

- Do not retain commented-out code as history; delete it and rely on version control.
- When disabled code is temporarily unavoidable, attach a directly adjacent allowed reason tag using `TAG(scope): explanation`, state why it remains and the condition for removal or restoration, and include an issue reference for a multi-line block.
- Do not use weak reasons such as `temporary`, `needed later`, `old code` or `do not remove`.
- Treat `TODO`, `FIXME`, `BUG`, `HACK`, `SECURITY`, `REVIEW` and `DEPRECATED` as tracked engineering markers, not casual prose labels.
- Do not disguise commented-out code, weaken comment-policy severity, add a baseline, suppress a finding or exclude a file to make strict comment analysis pass.
- Resolve comment-policy findings at their source and run the strict comment check while developing the change, before the full CI pipeline.

### PHP-FIG Standards Selection And Status

- Treat accepted PSRs as stable interoperability contracts.
- Treat PERs as versioned recommendations that may evolve; pin the intended major version.
- Do not make Draft or Review specifications mandatory project requirements without explicit approval and risk assessment.
- Do not present Abandoned proposals as PHP-FIG standards.
- Do not choose Deprecated standards for new code.
- Use PSR-4 instead of deprecated PSR-0.
- Use PER Coding Style for current new code instead of deprecated PSR-2.
- Preserve an older accepted contract only when compatibility with existing consumers requires it.
- Review the official status and current package versions before adding a new PHP-FIG dependency.
- Document which PSRs and PER versions form part of the public package or application contract.
- Do not advertise PHP-FIG compliance unless the relevant normative requirements and compatibility tests are satisfied.

## Types, Validation And Control Flow

### Type Safety And Data Boundaries

- Add `declare(strict_types=1);` to first-party PHP files unless project compatibility explicitly prevents it.
- Prefer strict comparisons (`===`, `!==`) by default.
- Do not rely on implicit type coercion.
- Normalize and validate external values at system boundaries.
- Do not pass unvalidated request, environment, database, message or file data directly into domain logic.
- Use explicit parameter, property and return types wherever practical.
- Prefer narrow, precise types over `mixed`, generic arrays and undocumented structures.
- Use enums or explicit value objects for finite states when they materially improve correctness.
- Use strict comparison modes where available:

```php
in_array($value, $items, true);
array_search($value, $items, true);
```

- Avoid ambiguous truthiness checks involving `0`, `"0"`, `""`, `false` and `null`.
- Compare against the intended state explicitly.
- Distinguish between:
  - missing,
  - empty,
  - false,
  - zero,
  - null,
  - and invalid values.
- Prefer `isset($array[$key])` for array-key existence, lookup guards and set-membership checks whenever a missing key and a `null` value are intentionally equivalent.
- Treat `isset()` as the default hot-path key check when its null semantics are correct.
- Use `array_key_exists()` only when the code must distinguish a present key containing `null` from a missing key.
- For null-insensitive nested existence checks, prefer direct `isset($data['parent']['child'])` access instead of multiple key checks.
- Do not call both `isset()` and `array_key_exists()` for the same key unless the null distinction is functionally required.
- Use `empty()` only when all PHP empty values are intentionally equivalent. Prefer explicit comparisons when `0`, `"0"`, `false`, `""` and `null` have different meanings.
- Validate and normalize untrusted data once at the trust boundary, then pass a typed or documented internal representation onward.
- Do not repeat identical validation, normalization or conversion at every internal layer or loop unless the value can change or crosses another trust boundary.
- Do not use null coalescing or null-safe access to conceal missing validation.
- Avoid undocumented sentinel values.
- Introduce result objects, enums or exceptions only when they improve a real contract. Do not wrap simple hot-path scalar or array results merely to add abstraction.

### Control Flow, Branching And Exceptions

- Use guard clauses and early returns to keep the primary execution path clear.
- Avoid deep nesting.
- Avoid unnecessary `else` branches after `return`, `throw`, `continue` or `break`.
- Avoid long `if` / `elseif` chains.
- Consider an exhaustive `match`, lookup map, enum method or dedicated handler when it makes dispatch clearer.
- Prefer `match` for finite value dispatch when strict comparison, exhaustiveness and lack of fall-through are desirable.
- Avoid a default `match` arm when explicitly handling every supported state provides better safety.
- Do not introduce handler classes or strategy hierarchies merely to eliminate a small conditional.
- Prefer the least complex control-flow structure that remains clear and extensible.
- Keep boolean expressions readable.
- Extract complex predicates into clearly named methods when the name communicates intent better than the expression.
- Avoid boolean parameters that substantially change a method’s behavior.
- Prefer separate operations, enums or explicit options when behavior diverges significantly.
- Do not use exceptions for normal expected branching.
- Use exceptions for failures that interrupt the normal contract and cannot be handled meaningfully at the current level.

## Classes, Methods, Files And Abstraction

### Cohesion, Size And Complexity

- Keep methods, functions and closures small, cohesive and clear.
- Interpret `small` as one cohesive responsibility with understandable control flow, not as a line-count target.
- Split code when responsibilities, branch count or execution paths become difficult to reason about.
- Avoid hidden side effects and mixed responsibilities.
- Make state changes explicit.
- Prefer immutable values where mutation is unnecessary.
- Avoid unnecessary inheritance hierarchies, wrappers, service layers, factories and indirection.
- Prefer deterministic functions for transformations and calculations.
- Avoid global mutable state.
- Treat static mutable state carefully, especially in long-running workers and application servers.
- Keep dependencies explicit.
- Avoid service locators and hidden dependency resolution.
- Add docblocks only when they communicate information PHP types cannot express clearly.
- Do not add docblocks that merely repeat method names or native type declarations.
- Do not use references as a presumed optimization.
- Use references only when reference semantics are functionally required.
- Do not extract every small block into another method.
- Keep cohesive low-level operations local when extraction would add dispatch without improving:
  - ownership,
  - reuse,
  - testability,
  - invariants,
  - or readability.
- Avoid chains of tiny method calls inside measured hot loops when equivalent local code is clearer and materially faster.
- Do not introduce DTOs, value objects, wrappers or custom collections into a hot path solely for stylistic consistency when they add allocations without enforcing a useful contract.

Use the following default limits:

```yaml
cognitive_complexity:
    class: 80
    function: 12
    dependency_tree: 120
```

- Treat cognitive complexity as a maintainability guard, not a runtime-performance target.
- Treat cognitive-complexity values as maximum limits, not utilization targets.
- Do not attempt to keep classes or functions close to their complexity limits.
- Prefer cohesive classes with low complexity when the responsibility is naturally simple.
- Do not increase complexity thresholds to pursue theoretical performance improvements.
- Prefer simplifying control flow before extracting additional methods or classes.
- Do not fragment cohesive hot-path logic into excessive wrappers solely to satisfy a complexity limit.
- Do not raise a project-wide limit to accommodate one parser, protocol implementation, state machine, generated file or performance-critical algorithm.
- Do not resolve a cognitive-complexity finding by:
  - raising a threshold,
  - adding or expanding a baseline,
  - excluding the affected code,
  - suppressing the rule,
  - or moving the code outside the detector's scope.
- Profiling evidence may guide how a complex parser, protocol implementation, state machine or hot algorithm is refactored, but it does not justify bypassing the detector.
- Simplify control flow, isolate a genuinely cohesive responsibility or correct a demonstrably misconfigured detector according to the quality-gate rules.
- A valid complexity finding remains unresolved until the code or the demonstrably incorrect detector configuration is corrected.
- Generated code may remain outside hand-written-code complexity limits only when:
  - it is deterministically generated,
  - the generator or source template remains analyzed and reviewed,
  - the exclusion is an established project policy,
  - and the exclusion is not being added merely to make a current finding pass.
- Do not relabel, move or regenerate hand-written code to obtain a generated-code exclusion.
- Configure dependency-tree analysis against relevant project root types instead of applying it blindly to every class.

### Structural Simplification, Type Budget And Call-Hop Reduction

**Default Ownership Rule**

- Prefer the smallest coherent structure that preserves:
  - correctness,
  - security,
  - cohesion,
  - explicit contracts,
  - maintainability,
  - interoperability,
  - and operational safety.
- Behavior remains inside its existing cohesive owner unless a separate type provides a concrete boundary or a demonstrable reduction in total system complexity.
- The default extraction target is a private method, not a new class.
- Treat class count, interface count, file count, public-type count, method count and call depth as diagnostic signals rather than independent optimization targets.
- Do not treat more classes, files, methods or abstractions as evidence of better architecture.
- Do not minimize symbol or file count blindly when separation protects a meaningful boundary.
- Do not merge unrelated behavior merely to reduce symbols, files, methods or directory entries.
- Do not split cohesive behavior solely to reduce line count, lower one local complexity score or display a design pattern.
- Optimize for the smallest coherent type system, not the smallest individual file.
- Structural simplification is subordinate to correctness, security, public contracts, operational stability and sustained successful RPM.
- Do not remove a meaningful boundary or a measured optimization merely to reduce class, method or file counts.
- Preserve deliberate, bounded duplication or precomputation when it materially improves sustained RPM and its consistency and lifecycle are controlled.

**Source-File And Type-Count Review**

- Review a library when its production source-file count appears disproportionate to its:
  - executable code,
  - number of public capabilities,
  - supported backends or providers,
  - public contracts,
  - security boundaries,
  - and independently managed lifecycles.
- File count is a review signal, not proof of a defect.
- A source file or type should normally represent a meaningful:
  - public capability,
  - backend or provider,
  - interoperability boundary,
  - security boundary,
  - lifecycle,
  - protocol concept,
  - reusable public value,
  - or independently owned responsibility.
- Reduce file count by removing unnecessary types and consolidating behavior into its correct cohesive owner.
- Do not reduce file count by placing unrelated classes or public symbols in one file.
- Prefer one primary public autoloadable symbol per file.
- Co-locate tightly coupled, single-use implementation details only when the project structure supports it and separate files add navigation or declaration overhead without architectural value.
- Do not create separate files for trivial wrappers that add no validation, behavior, contract, adaptation or operational value.

**New Type Justification**

- Every new production class, interface, enum, trait, DTO, result, wrapper, handler and source file must justify its independent existence.
- Test-only fakes, fixtures, data providers and framework-required support files may remain separate when they have a clear test or integration responsibility.
- Test or support structure does not justify expanding the production public surface.
- Create a new type only when it provides at least one meaningful benefit:
  - a public capability intentionally exposed to consumers,
  - a genuine backend, driver, provider or adapter implementation,
  - a stable public extension or interoperability contract,
  - a framework-required extension, integration or entry-point type,
  - stronger type safety,
  - invariant enforcement,
  - independent substitution,
  - a distinct lifecycle,
  - independently managed state,
  - a security or authorization boundary,
  - an independently selectable security policy,
  - transaction or persistence ownership,
  - an external-resource boundary,
  - public contract stability,
  - an immutable public configuration contract with meaningful validation,
  - a security-sensitive value object that prevents invalid or unsafe states,
  - a reusable result crossing a public boundary,
  - a protocol-defined object or message,
  - a complex algorithm with independent configuration, reuse or substitution,
  - a separately deployable, scalable or executable process boundary,
  - reuse by multiple otherwise independent owners,
  - or a demonstrated reduction in total system complexity that exceeds the added abstraction cost.
- Testability by itself does not justify a separate class.
- Public visibility by itself does not justify an interface.
- A block of code does not require a new class merely because it:
  - has a name,
  - can be tested separately,
  - contains several conditions,
  - can implement an interface,
  - resembles a design pattern,
  - or would reduce the line count of its current owner.
- Do not create a type merely to:
  - satisfy a pattern,
  - reduce line count,
  - lower a local complexity score,
  - enable mocking,
  - rename a scalar,
  - or make the directory structure appear more architectural.
- Count abstraction overhead as part of the design cost:
  - additional files,
  - autoloaded symbols,
  - constructor dependencies,
  - dependency-injection wiring,
  - runtime dispatch,
  - configuration,
  - testing surface,
  - stack depth,
  - navigation cost,
  - and maintenance burden.
- Reject abstractions whose expected value is speculative or smaller than their ongoing operational and cognitive cost.
- Record the justification when adding a new public type or a non-trivial internal abstraction.

**Remove Unnecessary Abstractions**

Remove or consolidate:

- Interfaces with one internal implementation when:
  - consumers cannot provide another implementation,
  - no backend, provider or interoperability boundary exists,
  - no independent substitution is required,
  - and no second implementation is part of the active design.
- Public wrappers that delegate every operation without adding:
  - adaptation,
  - validation,
  - policy,
  - authorization,
  - caching,
  - transaction ownership,
  - compatibility,
  - or lifecycle management.
- Internal helper classes containing only one trivial method.
- One-method classes that have no meaningful command, adapter, middleware, strategy, specification, value, result, extension or lifecycle role.
- Classes created only to avoid several private methods in one cohesive service.
- Separate validator classes for each field, claim, property or condition when one cohesive validator owns the complete operation.
- Separate factory, builder, manager, handler, processor and executor classes that form a pass-through chain for one capability.
- Separate result classes with equivalent success values and nearly identical failure semantics.
- Enums used only inside one class when private typed constants provide equivalent safety and clarity.
- Duplicate public entry points for the same capability.
- Repeated DTO, entity, transport, array and response conversions that do not represent real boundaries.
- Empty marker abstractions that enforce no contract and affect no behavior.
- Interfaces created only to make a concrete class mockable.
- Redundant names such as `Default`, `Base` or `Impl` when one implementation exists and no alternate implementation is part of the contract.
- Paired files such as `UserServiceInterface` and `UserService` when the interface has no concrete architectural purpose.

**Keep Small Classes When They Represent**

- A small class is not automatically unnecessary.
- Keep a small class when it represents:
  - a public capability,
  - a real adapter, driver, backend or provider,
  - a public extension point,
  - an immutable configuration contract,
  - a value object that enforces a meaningful invariant,
  - a security-sensitive value,
  - a protocol-defined object or message,
  - a reusable result crossing a public boundary,
  - a framework- or PHP-FIG-required interoperability type,
  - a distinct exception callers are expected to catch independently,
  - a separately managed lifecycle or resource,
  - a real command, middleware or process entry point,
  - or an independently selectable security or protocol operation.
- Keep classes separate when they represent:
  - different responsibilities,
  - different lifecycles,
  - unrelated dependencies,
  - independently replaceable implementations,
  - public extension contracts,
  - security or authorization boundaries,
  - transaction ownership,
  - external-provider boundaries,
  - independently scalable processes,
  - or independently testable policy with a real independent contract.
- A small class should remain only when its independent semantic or architectural value exceeds its file, dependency, navigation and dispatch cost.

**Private Method Preference**

- Prefer private methods when behavior:
  - has one clear owner,
  - shares the same lifecycle as its owner,
  - uses the same dependencies,
  - is not independently configurable,
  - is not reused by unrelated capabilities,
  - is not independently replaceable,
  - does not cross a security, authorization, transaction, persistence or provider boundary,
  - is not part of the supported public API,
  - does not need independently managed state,
  - and exists primarily to name, organize or simplify the owner's internal algorithm.
- Do not split a cohesive class only because it contains many private methods.
- Private-method preference does not override class cohesion or cognitive-complexity limits.
- Do not retain unrelated responsibilities in one class merely to avoid introducing a justified collaborator.
- Keep tightly related, single-use behavior together when it has one owner and doing so does not create mixed responsibilities or excessive branching.
- Split a class when it has:
  - multiple reasons to change,
  - unrelated dependencies,
  - separate lifecycle requirements,
  - independently replaceable behavior,
  - or responsibilities that remain understandable and useful outside the original owner.
- Inline a private method when it:
  - is used once,
  - performs a trivial operation,
  - does not name an important concept,
  - enforces no invariant,
  - isolates no meaningful decision,
  - and only adds another call hop.
- Keep or extract a private method when it:
  - names an important concept,
  - isolates a complex decision,
  - prevents meaningful duplication,
  - improves readability,
  - enforces an invariant,
  - or keeps the caller materially easier to understand.
- Reject a refactoring that lowers local cognitive complexity while increasing total system complexity.

**Class Consolidation Rules**

- Merge classes when they:
  - have the same lifecycle,
  - use substantially the same dependencies,
  - change for the same reason,
  - cannot be meaningfully used independently,
  - and become clearer as one cohesive type.
- Consolidate field, claim or rule validators when they share:
  - one owner,
  - one lifecycle,
  - one configuration model,
  - and one public capability.
- Keep such validators separate only when they are:
  - independently configurable,
  - independently reusable,
  - independently replaceable,
  - supplied by consumers,
  - part of a deliberate extension contract,
  - or security-significant as independent policies.

Prefer:

```php
final class JwtValidator
{
    public function validate(string $jwt): ValidationResult
    {
        $this->validateIssuer($jwt);
        $this->validateAudience($jwt);
        $this->validateTimestamps($jwt);

        return ValidationResult::valid();
    }

    private function validateIssuer(string $jwt): void
    {
    }

    private function validateAudience(string $jwt): void
    {
    }

    private function validateTimestamps(string $jwt): void
    {
    }
}
```

Instead of:

```text
JwtValidator
IssuerValidator
AudienceValidator
SubjectValidator
ExpirationValidator
NotBeforeValidator
IssuedAtValidator
JwtIdValidator
ValidationPipeline
ValidatorRegistry
ValidatorFactory
```

**Result-Type Consolidation**

- Prefer one result type for operations sharing the same:
  - success representation,
  - failure model,
  - lifecycle,
  - and public contract.
- Prefer a failure enum or reason code when failures differ only by category.
- Do not create `IssuerValidationResult`, `AudienceValidationResult`, `ExpirationValidationResult` and similar types when they carry equivalent data.
- Keep separate result types when:
  - their successful values differ materially,
  - callers handle them through different public contracts,
  - they expose different security guarantees,
  - or they adapt genuinely different external systems.
- Do not replace several precise and materially different results with one untyped generic result container.

Example:

```php
enum ValidationFailure
{
    case Malformed;
    case InvalidIssuer;
    case InvalidAudience;
    case Expired;
    case NotYetValid;
}

final readonly class ValidationResult
{
    public function __construct(
        public bool $valid,
        public ?ValidationFailure $failure = null,
    ) {
    }
}
```

**Public Capability Budget**

- Prefer one primary public entry point for each coherent capability.
- Prefer one public configuration model for each coherent capability unless configurations have materially different invariants.
- Prefer one public result model for operations sharing equivalent result semantics.
- A public result model does not require a dedicated result class.
- Use the simplest precise contract, such as a scalar, enum, documented array shape or object, according to the operation's semantics.
- Do not expose internal:
  - orchestration,
  - helpers,
  - validators,
  - mappers,
  - intermediate representations,
  - or implementation classes
  as public API unless consumer construction, substitution or extension is intentional.
- Remove public aliases and duplicate entry points unless backward compatibility requires them.
- When retaining a compatibility alias:
  - deprecate it,
  - point to the canonical replacement,
  - and define its removal timeline.
- Do not create an interface, DTO, value object, event, exception or adapter merely because code is packaged as a library.
- Retain stronger types and abstractions when they prevent invalid states or define a real public contract.

**Call-Hop Reduction**

- Reduce unnecessary:
  - classes,
  - interfaces,
  - traits,
  - files,
  - methods,
  - wrappers,
  - factories,
  - adapters,
  - managers,
  - handlers,
  - processors,
  - executors,
  - events,
  - DTO conversions,
  - and delegation layers.
- Remove pass-through layers that only:
  - forward the same arguments,
  - delegate to one collaborator,
  - return the delegated result unchanged,
  - rename an existing operation,
  - repeat validation already completed at a trusted boundary,
  - or convert between equivalent representations without enforcing a contract.
- Prefer a direct method call when:
  - the caller knows the required collaborator,
  - no implementation substitution is required,
  - no independent lifecycle exists,
  - no policy or invariant is enforced,
  - and the additional layer provides no architectural or measured value.
- Treat chains such as the following as review signals when intermediate layers add no responsibility:
  - controller → service → manager → handler → processor,
  - repository → gateway → adapter → client,
  - command → dispatcher → executor → worker,
  - and model → mapper → DTO → presenter → response mapper.
- Avoid unnecessary middleware, listeners, observers, decorators, wrappers, DTO conversions and container lookups on hot routes.
- Prefer direct calls over dynamic dispatch when extension or substitution is not required.
- Avoid chains of tiny method calls in measured hot paths when equivalent cohesive code is clearer and materially faster.
- Keep one internal representation through as much of the execution path as practical.
- Perform framework, PSR, transport, persistence and provider adaptation once at the relevant boundary.
- Avoid repeated validation, normalization, mapping and serialization at successive call hops.
- Avoid hidden global state and service location.
- Evaluate structural simplification by its effect on:
  - sustained successful RPM,
  - executed method calls,
  - autoloaded symbols,
  - bootstrap work,
  - allocations,
  - conversions,
  - cognitive complexity,
  - dependency count,
  - test surface,
  - stack-trace clarity,
  - and navigation cost.
- In measured hot paths, prefer fewer meaningful call hops when equivalent behavior remains clear and safe.
- Do not require a dedicated benchmark for every trivial pass-through removal.
- Require representative measurement when structural simplification changes:
  - public behavior,
  - lifecycle,
  - dependency resolution,
  - request composition,
  - middleware,
  - database access,
  - serialization,
  - or another frequently executed path.
- When two structures provide equivalent correctness, safety and sustained RPM, prefer the one with fewer unnecessary abstractions, dependencies, conversions, execution hops and maintenance costs.

**Structural Review Triggers**

- Review whether consolidation is appropriate when:
  - many production source files contain only one trivial method,
  - many classes only delegate to another class,
  - the public type count substantially exceeds the number of public capabilities,
  - most interfaces have only one non-extensible implementation,
  - several result classes carry the same fields,
  - several validators share one owner and lifecycle,
  - one capability is represented by several managers, handlers, processors or executors,
  - or understanding one operation requires navigating through several files without encountering a meaningful boundary.
- Exclude from structural-density calculations where appropriate:
  - tests,
  - fixtures,
  - generated files,
  - stubs,
  - migrations,
  - examples,
  - benchmarks,
  - framework-required entry points,
  - trivial exceptions,
  - attributes,
  - enums,
  - and legitimate immutable value objects.
- Do not consolidate solely to improve a ratio.
- Keep consolidation within the active task unless broader restructuring is explicitly approved.
- Report larger structural concerns separately instead of hiding repository-wide cleanup inside feature or bug-fix work.
- Consolidate only when the result has:
  - clearer ownership,
  - fewer unnecessary hops,
  - fewer redundant public types,
  - and lower total complexity.
- Do not enforce arbitrary minimum or maximum class line counts.
- Small value objects, enums, exceptions, DTOs, attributes and adapters remain valid when they represent meaningful concepts.

**Non-Blocking Structural Review Thresholds**

- Use the following as default review triggers, not automatic defects or quality-gate failures:

```yaml
structural_review:
    production_source_files_min: 20
    small_file_executable_lines_max: 40
    small_file_ratio_review_above: 0.35
    median_executable_lines_review_below: 50
    trivial_single_method_class_ratio_review_above: 0.20
    pass_through_class_ratio_review_above: 0.10
    require_justification_for_new_public_type: true
    require_boundary_for_single_implementation_interface: true
```

- Interpret these values as follows:
  - ratio-based review is unnecessary for packages below `production_source_files_min`,
  - a high small-file ratio requires ownership and boundary review,
  - a high trivial single-method-class ratio requires consolidation review,
  - and a high pass-through-class ratio requires immediate call-hop review.
- Crossing a threshold does not prove the design is wrong.
- These values describe package-level structural density; they are not per-file size targets, minimums or maximums.
- Do not split or merge a file solely to move a metric across a review threshold.
- Do not convert these review triggers into blocking detector rules without project-specific calibration, evidence and explicit approval.
- Do not weaken an established detector or raise an established blocking threshold merely because the current design exceeds it.

### Interfaces, Containers And Extension Contracts

- Do not create an interface for every class by default.
- Create an interface only when it defines a meaningful contract or substitution boundary.
- When an interface is justified, keep it narrow, behavior-focused and stable.
- Introduce an interface when at least one of the following is true:
  - multiple implementations currently exist,
  - consumers must provide their own implementation,
  - the contract forms part of the library's supported public extension surface,
  - the implementation crosses a significant infrastructure or third-party boundary,
  - runtime strategy or driver selection is required,
  - or separate modules must depend on a stable contract rather than implementation details.
- Prefer a concrete `final` class for internal implementation details that are not designed for extension or substitution.
- Public immutable value objects, DTOs, factories, exceptions and utility types may remain concrete when no substitution contract is required.
- Prefer testing through observable behavior or by substituting real architectural boundaries.
- Do not introduce an interface solely to expose internals for mocking.
- Keep internal interfaces private to the package or module when they are not part of the supported public API.
- Do not expose an interface publicly unless compatibility and long-term maintenance of that contract are intentional.
- Design public library extension through interfaces and composition rather than requiring consumers to inherit from concrete classes.
- Keep public contracts minimal because each method added to an interface becomes a compatibility obligation.

**Dependency Containers And Composition Roots**

- Use PSR-11 `ContainerInterface` only when an interoperable container boundary is genuinely required.
- Keep container access at the composition root, framework bridge, plugin loader or another explicit integration boundary.
- Do not inject a container into domain, application or library objects so they can retrieve their own dependencies.
- Do not use PSR-11 as a service locator.
- Resolve dependencies once and inject the required concrete services or narrow interfaces.
- Do not perform repeated container lookups inside requests, loops or other hot paths.
- Compile or cache container wiring during build or bootstrap where the implementation supports it.
- Do not require `psr/container` in a reusable package unless the package actually consumes or provides the PSR-11 contract.
- When a framework container is adapted to PSR-11, perform the adaptation once at the integration boundary.

### Enums, Constants And Value Types

- Use enums for meaningful closed sets of domain or contract values.
- Do not create an enum merely to replace every group of constants or strings.
- Prefer an enum when:
  - the value has a distinct domain identity,
  - only a fixed set of values is valid,
  - exhaustive handling is desirable,
  - type-safe parameters or return values improve correctness,
  - or behavior meaningfully belongs to each case.
- Use backed enums when a stable scalar representation is required for storage, serialization, messaging or external contracts.
- Validate external backed-enum values explicitly with `tryFrom()` or an equivalent boundary conversion.
- Do not pass arbitrary external strings deep into the application when they represent a known enum type.
- Prefer class constants when values are:
  - implementation-local,
  - configuration keys,
  - header names,
  - default limits,
  - regular-expression fragments,
  - numeric flags,
  - algorithm parameters,
  - cache-key prefixes,
  - or other values without independent domain identity.
- Prefer private constants inside the owning class when the values are used only there.
- Do not create a separate enum or constants file for values that belong exclusively to one class when private typed constants provide equivalent safety and clarity.
- Do not create generic `Constants`, `CommonConstants` or `GlobalConstants` classes.
- Keep constants close to the behavior that owns them.
- Do not use an enum for an open-ended or externally extensible set.
- Do not use an enum when new values may be introduced independently by consumers, plugins, providers or third-party systems.
- Do not create an enum for a single local conditional unless it improves a public contract or prevents an invalid state.
- Do not attach unrelated utility behavior to enums merely to avoid creating an appropriate service.
- Prefer exhaustive `match` handling for enums when every case must be considered.
- Avoid a default `match` arm for enums when explicit handling provides safer change detection.

### Autoloadable Symbol And File Behavior

- Keep classes, interfaces, traits, enums and functions in predictable Composer-autoloadable locations.
- Keep autoloadable files free from request-specific top-level execution and unrelated side effects.
- Prefer file-scope declarations with runtime decisions inside methods.
- Avoid conditional class, function, enum, interface or trait declarations unless explicitly required.
- Avoid named functions declared inside methods, conditions or loops.

## Request Processing, Data Loading And Serialization

### Request-Path Execution

- Minimize the amount of PHP code executed per request.
- Keep the common request path short, direct and predictable.
- Avoid loading, resolving, validating, constructing or serializing data that the request does not use.
- Do not add a cross-cutting layer to every request when only a subset of routes requires it.
- Compile stable routes, configuration, dependency mappings and metadata before serving traffic.
- Do not perform route discovery, directory scanning, annotation parsing, reflection discovery or container compilation during requests.
- Avoid repeated `.env`, YAML, XML or JSON configuration parsing in request paths.
- Avoid exceptions for expected control flow.
- Validate and normalize untrusted input once at the trust boundary, then use the validated internal representation.
- Do not repeat equivalent validation at every internal layer.
- Keep response payloads limited to fields required by the caller.
- Avoid eager object hydration when arrays, scalar projections or streamed rows are sufficient.
- Avoid callbacks and intermediate arrays in large, frequently executed transformations when one explicit loop performs the same work.
- Avoid synchronous database, filesystem, network or process calls that are not required to complete the response.
- Batch required I/O whenever batching improves total RPM.
- Use bounded concurrency for independent I/O only when it improves end-to-end throughput and does not overload downstream systems.
- Cache or precompute stable high-cost work when the hit rate and reuse justify construction, storage and invalidation costs.
- Do not add caching to one-time or low-reuse operations.
- Keep mandatory observability structured, bounded and inexpensive.
- Do not construct expensive log context when the corresponding log level is disabled.
- Keep authentication and authorization correct while avoiding duplicate resolution, parsing and database access.
- Reuse already loaded immutable request data instead of fetching or transforming it repeatedly.
- Remove dead request-path work before pursuing syntax-level micro-optimizations.

### Lazy, Eager And Prefetched Loading

- Do not treat lazy loading, eager loading or prefetching as universally faster.
- Select the strategy using representative:
  - sustained successful RPM,
  - query count,
  - I/O round trips,
  - first-use latency,
  - warm-reuse latency,
  - peak memory,
  - worker density,
  - tail latency,
  - and resource lifetime.
- Use lazy loading for optional, expensive work that most execution paths do not require.
- Use eager loading or prefetching when:
  - data or dependencies are required by nearly every execution,
  - related work can be batched,
  - initialization can be amortized safely,
  - or delayed loading would create repeated I/O or unpredictable latency.
- Do not use lazy loading merely to hide expensive work behind:
  - property access,
  - magic methods,
  - service locators,
  - container resolution,
  - reflection,
  - database relations,
  - filesystem discovery,
  - configuration lookup,
  - secret-store access,
  - or network calls.
- Keep expensive I/O explicit at the request, service, repository, adapter or operation boundary.
- Avoid ORM or repository lazy loading inside loops when it can create N+1 queries.
- Prefer explicit joins, projections, batch lookups, `WHERE IN` queries, data loaders or bounded prefetching when they reduce total round trips.
- Do not eagerly load complete object graphs when only a scalar, identifier, aggregate or narrow projection is required.
- Select only the fields and relationships needed by the current operation.
- Do not eager-load large or unbounded relationships without:
  - limits,
  - pagination,
  - streaming,
  - cursors,
  - or chunking.
- Prefer bounded prefetching when full eager loading would exceed memory, result-size, query, lock or transaction budgets.
- Do not issue several lazy loads for values that can be resolved in one request or one database round trip.
- Do not perform eager initialization for optional integrations that most requests never use.
- For optional dependencies:
  - initialize lazily,
  - memoize only when reuse is expected,
  - keep the lifecycle explicit,
  - and ensure state cannot leak across requests or callers.
- For dependencies used by nearly every request, compare eager startup construction with lazy first-use construction and repeated lazy checks.
- Validate mandatory dependencies, configuration and required backing services before traffic is accepted.
- Compile stable routes, dependency mappings, event mappings, serializer metadata and similar structures during build, release or startup.
- Do not lazily perform directory scanning, annotation parsing, reflection discovery, route compilation or container compilation during requests.
- Keep first-use initialization deterministic and bounded.
- Avoid lazy initialization whose first call produces unacceptable `p95` or `p99` latency.
- Prevent concurrent first-use stampedes when several workers or requests may initialize the same expensive resource.
- Use locking, single-flight behavior, build-time generation or deployment warm-up only when the coordination cost is justified.
- Do not let eager cache or metadata warming overload databases, caches or external services during startup or deployment.
- Warm only representative high-reuse entries, routes, classes or metadata.
- Use bounded concurrency for warm-up.
- Keep warm-up failure behavior explicit and operationally safe.
- Generators, iterators, cursors and streams are lazy execution mechanisms, not automatically lower-cost execution mechanisms.
- Use lazy iteration for large or unbounded data when reduced peak memory outweighs longer resource occupancy.
- Do not let a lazy iterator retain:
  - a database connection,
  - transaction,
  - lock,
  - file descriptor,
  - stream,
  - HTTP response body,
  - or external-service lease
  longer than intended.
- Prefer bounded eager chunks when they:
  - release resources sooner,
  - increase worker availability,
  - improve batching,
  - or produce higher total RPM.
- Do not convert a lazy iterable into a full array unless full materialization is required.
- Do not consume a lazy stream more than once unless it is explicitly rewindable or buffered.
- Cache a lazily loaded value only when:
  - it is immutable or safely scoped,
  - repeated reuse is expected,
  - memory remains bounded,
  - invalidation behavior is defined,
  - and persistent-worker state cannot leak across callers.
- Do not memoize request-specific, user-specific, tenant-specific, authorization-sensitive or secret data in static or long-lived objects.
- In persistent workers, test:
  - first initialization,
  - repeated reuse,
  - request reset,
  - tenant isolation,
  - failure recovery,
  - and memory stability.
- Treat eager validation differently from eager data loading:
  - eagerly validate mandatory configuration and contracts before accepting work,
  - but do not eagerly fetch optional business data that the operation may never use.
- Treat eager dependency construction differently from eager external connection:
  - constructing a lightweight immutable client may be safe,
  - opening a database, cache, file or network connection should remain demand-driven unless startup validation explicitly requires it.
- Do not keep an external connection open merely because its client object was eagerly constructed.
- Measure cold-first-use and warm-reuse behavior separately.
- Include the actual request mix so optional-path savings and common-path penalties are represented correctly.
- Prefer the strategy with the highest sustainable successful RPM that stays within explicit:
  - query,
  - latency,
  - memory,
  - worker-density,
  - connection,
  - and operational-stability budgets.

- Do not rely on lazy loading in performance-sensitive paths without verifying the resulting query count.
- Benchmark lazy, joined, batched and chunked relation-loading strategies with representative cardinality and concurrency.
- Do not eager-load large relationship graphs when the request needs only a subset of fields or relations.
- Avoid N+1 query patterns.
- Prefer explicit eager loading, joins, projections or batched prefetching when related data is required across a collection.

- Make optional library integrations lazy so unused dependencies do not affect bootstrap or common-path RPM.
- Eagerly construct lightweight immutable dependencies used by nearly every operation only when startup cost, retained memory and failure behavior are acceptable.
- Do not let lazy library initialization hide database, filesystem, network, reflection or container work.

### HTTP Messages, Handlers And Middleware

- Use PSR-7 HTTP messages at public package, middleware and framework-bridge boundaries where implementation independence is required.
- Do not convert repeatedly between framework-native requests and PSR-7 messages.
- Adapt once at the boundary and retain one representation through the internal request path.
- Respect PSR-7 message immutability and stream cursor semantics.
- Avoid long chains of `with*()` calls that create unnecessary message copies in measured hot paths; group boundary changes where practical.
- Do not assume request or response bodies are rewindable, seekable or safely readable more than once.
- When a reusable component must create PSR-7 messages or streams, depend on the narrow PSR-17 factory interfaces it actually needs.
- Do not couple reusable middleware or handlers to one concrete PSR-7 implementation.
- Use PSR-15 for reusable server request handlers and middleware.
- Do not add middleware layers merely to claim PSR-15 compliance.
- Keep exception-to-response handling at the outer request boundary so failures produce controlled responses.
- Keep authorization and other security middleware ordered explicitly.
- Benchmark the complete middleware stack and remove layers that provide no required behavior.
- Use PSR-13 only when the application or library genuinely models hypermedia links independently of serialization format.
- Do not introduce PSR-13 for ordinary URLs, redirects or APIs that do not expose hypermedia controls.

### JSON And Request Bodies

- Use `json_validate()` when only JSON syntax validation is required and the decoded value will not be consumed.
- Do not call `json_validate()` immediately before `json_decode()` on the normal valid path because it parses the payload twice.
- When decoded data is required, decode once and use `JSON_THROW_ON_ERROR` where appropriate.
- Keep validation and decoding depth and UTF-8 policies consistent.
- Bound JSON payload size and nesting depth before parsing untrusted input.
- Do not decode the same immutable JSON payload repeatedly in one request.
- Reuse the decoded representation when ownership and mutation rules are clear.
- Avoid repeated array-to-object and object-to-array conversions after decoding.
- Benchmark validation-only, decode-only and decode-plus-validation paths separately when JSON processing materially affects RPM.

- Ensure exactly one request-boundary component owns body parsing.
- Treat request-body consumption as destructive.
- On supported runtimes, consider `request_parse_body()` for `multipart/form-data` or `application/x-www-form-urlencoded` bodies on non-`POST` requests.
- Call `request_parse_body()` at most once per request.
- Do not read `php://input` before calling `request_parse_body()`.
- When several consumers need the raw body, buffer it once through an explicitly bounded mechanism and share that representation.
- Apply explicit limits for:
  - total body size,
  - file size,
  - file count,
  - input-variable count,
  - multipart-part count,
  - and nesting depth.
- Handle parse failures and invalid-limit errors at the request boundary.
- Do not copy parsed values into superglobals unless an established application contract requires it.
- Do not use multipart or form parsers for JSON payloads.

### Serialization And Output

- Avoid repeated serialization and deserialization of the same payload.
- Do not construct response fields the caller does not need.
- Bound internal and external payload sizes.
- Stream large exports and downloads instead of buffering the entire output.
- Avoid many tiny writes when output can be safely buffered or streamed more efficiently.
- Do not build one extremely large in-memory string merely to reduce output calls.

## Collections, Iteration And Memory

### Arrays, Strings And Membership

- Prefer direct array access guarded by `isset()` when null and missing are equivalent.
- Use `array_key_exists()` only for true null-presence semantics.
- For repeated membership checks, prefer a keyed set and `isset($set[$key])` over repeated `in_array()` scans when the one-time set construction is justified.
- Use strict `in_array($value, $items, true)` for one-off membership checks where a keyed set would add unnecessary allocation.
- Avoid calling `array_keys()` merely to iterate or test membership.
- Avoid `array_map()`, `array_filter()` and `array_reduce()` chains on large or hot-path arrays when one explicit loop can avoid callbacks, intermediate arrays and repeated traversal.
- Use native array functions when they perform the complete operation clearly and without creating unnecessary intermediate data.
- Avoid repeated `array_merge()` in loops; append directly or accumulate chunks and merge once when appropriate.
- Avoid the array spread operator in large repeated merges when it creates avoidable copies.
- Avoid copying large arrays merely to preserve style. Mutate only when ownership is clear and mutation is safe.
- Do not build an index, lookup map or transformed array unless its reuse saves more work than its construction and memory cost.

**Native Array And String Operations**

- Prefer `array_is_list()` over manually comparing keys when list detection is required.
- Use `array_key_first()` and `array_key_last()` when only the key is required.
- On supported runtimes, use `array_first()` and `array_last()` when only the value is required and `null` ambiguity is acceptable.
- Use `array_find()` or `array_find_key()` when their nullable return semantics match the contract.
- Prefer `array_find_key()` when a matching value may be `null` and absence must remain distinguishable.
- Use `array_any()` and `array_all()` for short-circuit predicate checks when their callback cost is acceptable.
- In large or measured hot paths, compare callback-based helpers against a direct `foreach` with an early return.
- Do not use `array_filter()` merely to determine whether any matching element exists.
- Prefer `str_contains()`, `str_starts_with()` and `str_ends_with()` over error-prone `strpos()` comparison idioms when byte-string semantics are correct.
- Use `mb_*` or grapheme-aware operations only when Unicode semantics are required.
- Do not pay multibyte or grapheme-processing cost for ASCII-only identifiers, protocol tokens or internal keys.
- Do not replace one direct operation with several native calls that introduce extra passes or allocations.

### Iteration And Algorithmic Work

- Treat total work, algorithmic complexity, allocations and I/O as more important than the number of visible loops.
- When multiple loops process the same dataset in the same execution path, evaluate whether they can be safely combined.
- Combine loops when doing so removes meaningful repeated work without harming clarity or testability.
- Do not force unrelated responsibilities into one loop merely to reduce the loop count.
- A clear two-pass `O(n)` solution is preferable to a coupled or error-prone one-pass implementation.
- Avoid repeatedly scanning the same collection for values that can be indexed once.
- Build keyed lookup maps or sets when repeated membership checks would otherwise require linear searches.
- Question nested loops and repeated searches that create avoidable `O(n²)` behavior.
- Short-circuit iteration as soon as the required result is known.
- Avoid sorting an entire dataset when only a minimum, maximum, first match or limited top set is needed.
- Avoid repeated filtering, mapping, sorting, grouping and reindexing of the same large dataset.
- Be aware of hidden full passes and temporary arrays created by chained array or collection operations.
- In measured hot paths, prefer a clear explicit loop when it avoids several traversals and allocations.
- Move invariant calculations, parsing, normalization, pattern preparation and lookups outside loops.
- Never perform database, network, filesystem, process or other expensive I/O inside a loop when the work can be batched or prefetched.
- Apply bounded chunking when a single batch could exceed memory, query-size, payload, lock or downstream-service limits.

- Prefer `foreach` for normal array and iterable traversal.
- Use `for` when numeric indexes, counters or bounded positional access are intrinsic to the algorithm.
- Use `while` when iteration is naturally condition-driven.
- Do not choose `for`, `foreach` or `while` based solely on generic syntax benchmarks.
- Do not convert associative arrays into key lists merely to iterate with `for`.

**By-Reference `foreach` Cleanup**

- Avoid by-reference `foreach` unless in-place mutation is required and preferable to constructing a replacement array.
- After a by-reference `foreach`, the loop variable remains bound to the final iterated element.
- Call `unset($item)` immediately after the loop when execution may continue in the same variable scope.
- Cleanup is required when:
  - another loop or assignment may reuse the loop variable,
  - additional logic follows the loop,
  - the reference points to an array or object property,
  - a `catch` or `finally` block may continue execution in the same function,
  - or protecting against likely later refactoring is more valuable than removing one cleanup operation.
- Cleanup may be omitted only when control immediately and unconditionally exits the current variable scope:
  - the loop is followed directly by `return`,
  - the loop is the final statement in the function or closure,
  - or execution immediately throws an exception that is not handled within the same function scope.
- Do not omit cleanup merely because the method is currently short when additional code already follows the loop.
- Do not use `unset()` inside the loop.
- Do not generalize this rule to ordinary variables or non-reference loops.
- The `unset()` breaks the lingering reference; it is a correctness operation, not a memory-release optimization.

Required cleanup example:

```php
foreach ($items as &$item) {
    $item->normalize();
}

unset($item);

persist($items);
```

Safe omission at an immediate scope exit:

```php
foreach ($items as &$item) {
    $item->normalize();
}

return $items;
```

- Avoid repeatedly evaluating non-trivial expressions in loop conditions.
- Precompute loop bounds when doing so removes meaningful work, stabilizes intended behavior or improves clarity.
- Do not cache trivial values by default when the benefit is unmeasured and the additional state reduces readability.

### Data Movement, Memory And Variable Lifetime

- Treat peak memory and data movement as first-class performance concerns.
- Avoid materializing an entire dataset when it can be processed incrementally.
- Prefer generators, iterators, streams, cursors or chunking for large or potentially unbounded datasets.
- Do not convert a generator, cursor, stream or iterator into an array unless full materialization is required.
- Avoid unnecessary intermediate arrays and repeated array copies.
- Avoid repeatedly converting the same data among arrays, objects, JSON, XML and serialized formats.
- Keep data in the simplest useful representation for as long as practical.
- Select and retain only the fields needed by the current operation.
- Avoid hydrating large object graphs for read-only projections, reports or exports.
- Prefer incremental encoding and output for large responses.
- Use temporary streams or files when keeping the entire generated payload in memory is unnecessary.
- Close files, streams, cursors, processes and external resources through their explicit lifecycle APIs when they are no longer needed.
- Allow ordinary PHP variables and values to be released naturally at scope exit.
- Avoid unbounded in-memory accumulation in workers, consumers, daemons and long-running commands.
- Reset request-specific or job-specific state in long-running processes.
- Use worker recycling as a safety mechanism, not as a substitute for fixing memory growth.
- Do not increase memory limits to hide avoidable full-dataset loading or leaks.

- Do not use `unset()` as a routine performance or memory optimization.
- Do not add `unset()` after ordinary assignments, method calls, loops or processing steps.
- Rely on PHP scope and reference counting for ordinary local variables.
- Do not call `unset()` inside hot loops unless element removal is required by the algorithm.
- Do not unset a value immediately before `return`, function completion or scope exit.
- Do not unset parameters or object properties merely because they are no longer used locally.
- Avoid repeated removal of elements from large arrays as a memory strategy; mutation, hash-table maintenance and allocator churn can cost more than retaining the value briefly.
- Prefer smaller scopes, dedicated per-job methods, generators, streaming and bounded chunking over manual cleanup.
- Use `unset()` only when:
  - breaking the lingering reference after a by-reference `foreach`,
  - removing a key or value is required by program behavior,
  - a demonstrably large temporary value otherwise remains live across substantial later work,
  - or profiling shows that earlier release materially reduces peak memory without harming throughput.
- Treat early object destruction as observable behavior when destructors or external resources are involved.
- Use explicit lifecycle operations such as `fclose()` for external resources; do not use `unset()` as a substitute.
- Do not bulk-apply `unset()` through automated refactoring.
- Benchmark elapsed time and peak memory before retaining manual lifetime management.

- Do not assume that allocating more memory is cheaper than performing another CPU operation.
- A heap allocation, PHP array growth, object construction, reference-count update or cache miss can cost far more than a simple arithmetic operation, comparison or branch.
- Prefer one extra trivial CPU operation over a new allocation or data copy when both produce equivalent behavior.
- Prefer bounded additional memory when it eliminates materially more expensive work, such as:
  - repeated database or network I/O,
  - repeated parsing or serialization,
  - repeated full scans,
  - expensive recomputation,
  - or avoidable lock contention.
- Trade memory for speed only when the retained data will be reused enough to justify its allocation, initialization and lookup cost.
- Keep every memory-for-speed trade bounded by a known maximum, TTL, request scope, chunk size or eviction policy.
- Do not retain large maps, caches or object graphs for speculative reuse.
- Avoid memory usage that risks swapping, container eviction, PHP memory-limit failure or reduced worker density; these outcomes can produce severe latency regressions.
- Remember that PHP arrays are hash tables with substantial per-element overhead. Do not use large arrays as free or low-cost memory.
- For repeated membership checks, a keyed set with `isset()` may be faster than repeated linear scans, but build it only when the number of lookups justifies the one-time allocation.
- For one pass over a large dataset, prefer streaming or a direct loop instead of building additional indexes or copies.
- Measure wall-clock time, sustained throughput and peak memory together.
- Choose the fastest measured option that remains within explicit stability, memory, concurrency and capacity limits.
- Resource efficiency is secondary to speed unless resource pressure reduces throughput, increases latency or threatens operational stability.
- Prefer predictable, bounded memory consumption over the lowest possible memory consumption.
- Do not optimize memory in a way that adds significant CPU work unless memory pressure, worker density or peak-memory limits require it.

## Security, Identity And Cryptography

### Security And Operational Safety

- Apply secure-by-default design and OWASP-aligned practices where relevant.
- Treat validation, escaping, authorization, secrets management and dependency hygiene as mandatory.
- Mark sensitive parameters for stack-trace redaction when the supported runtime provides `#[\SensitiveParameter]`, while continuing to keep secrets out of logs and exception messages.
- Validate at trust boundaries.
- Encode for the destination context.
- Prefer safe failure, reduced blast radius, idempotency and operationally safe behavior.
- Consider logs, metrics, traces, health checks, timeouts, retries and configuration discipline.
- Avoid exposing sensitive diagnostic or implementation details in production errors.
- Ensure performance optimizations cannot bypass authorization, tenant isolation, validation or audit requirements.
- Treat resource exhaustion as both a security and reliability concern.
- Bound request sizes, collection sizes, recursion, concurrency, execution time and retry behavior.

### Security-Critical Libraries And Time

- Apply maximum-throughput rules to security-sensitive libraries only after correctness and security requirements are fully preserved.
- Never weaken:
  - cryptographic algorithms,
  - entropy sources,
  - secret handling,
  - timing-safety requirements,
  - replay protection,
  - rate limiting,
  - token validation,
  - authorization,
  - or input validation
  to improve RPM.
- For OTP libraries:
  - avoid unnecessary object creation and repeated normalization,
  - validate reusable configuration once where safe,
  - reuse immutable algorithm configuration,
  - avoid repeated encoding or time-source work within the same operation,
  - and use timing-safe comparison where required.
- Do not cache generated OTP values, secrets or verification decisions across callers unless the protocol explicitly requires it and scope is secure.
- Benchmark OTP generation and verification separately.
- Include valid, invalid, expired, malformed and replay-related paths in representative benchmarks.
- Treat rate limiting and replay protection as part of the production throughput model rather than removing them from tests.

**Time And Clock Boundaries**

- Use PSR-20 `ClockInterface` when a reusable or test-sensitive component requires an interoperable source of current time.
- Inject the clock through construction or explicit configuration.
- Do not resolve the clock repeatedly from a container.
- Read the clock once per logical operation unless the protocol explicitly requires another reading.
- For TOTP verification, capture the current time once and derive every candidate time step from that value.
- For repeated expiration or timeout calculations in one operation, reuse the captured timestamp or immutable time value.
- Production clocks must represent real current time according to the application's documented timezone contract.
- Tests may use deterministic or frozen clocks.
- Do not use process-global clock mutation when an injected clock can provide isolated behavior.
- Do not introduce a clock abstraction into purely deterministic code that receives time as an explicit input.
- In an ultra-hot internal loop, pass a precomputed timestamp rather than calling `ClockInterface::now()` repeatedly.
- Include clock-call and date-conversion costs in OTP and expiration benchmarks when they materially affect RPM.

### Hashing And Identifier Selection

- Select a hashing primitive by purpose before considering benchmark speed.
- Do not treat cryptographic hashes, non-cryptographic hashes, HMACs, password hashes, checksums and unique identifiers as interchangeable.
- Optimize hashing only after preserving the required collision resistance, authenticity, interoperability and threat model.
- Benchmark hashing with the project's actual:
  - PHP version,
  - CPU architecture,
  - payload sizes,
  - call frequency,
  - output representation,
  - and request lifecycle.
- Do not choose an algorithm solely from a large-buffer throughput benchmark.
- Measure the effect on complete sustained successful RPM in addition to isolated hashes per second or bytes per second.

**Non-Cryptographic Hashing**

- Use non-cryptographic hashes only when maliciously chosen collisions cannot compromise:
  - authorization,
  - authentication,
  - integrity,
  - cache isolation,
  - deduplication correctness,
  - database uniqueness,
  - signatures,
  - or security decisions.
- On supported PHP versions, prefer `xxh3` as the first candidate for high-throughput non-cryptographic hashing when a 64-bit result is sufficient.
- Prefer `xxh128` when a wider 128-bit result materially reduces accidental-collision risk and the additional output size is acceptable.
- Use MurmurHash variants when compatibility, seeded distribution or an existing contract requires them and representative benchmarks justify the choice.
- Use `crc32c`, `crc32`, `crc32b` and Adler variants only for checksums, protocol compatibility or corruption detection where their collision properties are acceptable.
- Do not use CRC, xxHash, MurmurHash, FNV, Jenkins or Adler hashes for cryptographic integrity, secrets, signatures, authentication tokens or attacker-controlled security decisions.
- Treat every non-cryptographic digest as collision-prone.
- Define how collisions are detected or handled before using a non-cryptographic hash as a lookup key, cache key, fingerprint or deduplication hint.
- Prefer the shortest output that satisfies measured collision and storage requirements; do not widen every hash by default.
- Use raw binary output internally when storage, comparison and transport contracts support it.
- Encode to hexadecimal, Base64 or another text format only at a boundary that requires text.
- Avoid hashing data that can be used directly as a safe bounded scalar key without additional work.
- Do not repeatedly hash the same immutable value in one request; compute once and reuse the digest when the reuse is measurable and scope is clear.

**Cryptographic Digests**

- Use a cryptographic hash when collision resistance or adversarial input matters but no secret-key authenticity is required.
- Prefer `sha256` as the default interoperable cryptographic digest for new general-purpose designs.
- Consider `sha512`, `sha512/256` or another approved cryptographic algorithm only when:
  - interoperability permits it,
  - output requirements are satisfied,
  - and representative benchmarks on target hardware show a useful throughput improvement.
- Do not assume `sha256` is always faster than SHA-512-family algorithms on every CPU.
- Do not use `md5` or `sha1` for new collision-sensitive or security-sensitive designs.
- Preserve legacy algorithms only when required by an established protocol or compatibility contract.
- Do not replace a protocol-defined hash merely because another algorithm is faster.
- Use streaming hash APIs or file-hashing APIs for large inputs instead of loading the complete payload into memory.
- Avoid hashing the same payload multiple times with different encodings unless the external contract requires it.
- Do not use a plain cryptographic digest where authenticity requires a secret; use HMAC or an appropriate digital-signature primitive.

**Unique And Opaque Identifiers**

- Hashing does not create uniqueness.
- Do not use a hash of a timestamp, sequence, email address, database identifier or other predictable value as a secure opaque identifier.
- For random opaque identifiers, generate sufficient bytes with `random_bytes()` or an appropriate cryptographically secure identifier implementation.
- Encode random identifiers only once for storage or transport.
- Enforce uniqueness with a database or storage-level unique constraint and handle the extremely rare collision with a bounded retry when required.
- For deterministic identifiers or fingerprints:
  - define the canonical input representation,
  - define whether hostile collisions are possible,
  - define the acceptable collision probability,
  - and define collision handling.
- Use a cryptographic digest such as `sha256` for deterministic identifiers when collisions could affect correctness, tenancy, authorization, financial state or security.
- Consider `xxh128` for non-adversarial deterministic fingerprints only when collision handling exists and representative throughput measurements justify it.
- Do not truncate identifiers or digests without an explicit collision analysis.
- Do not confuse obscuring an identifier with securing access to the underlying resource.

**HMAC Selection And Usage**

- Use HMAC when a shared secret must authenticate a message or detect tampering.
- Prefer `hash_hmac('sha256', ...)` as the default interoperable HMAC for new general-purpose designs.
- Consider HMAC-SHA-512 or HMAC-SHA-512/256 only when:
  - both sides support the algorithm,
  - the protocol allows algorithm selection,
  - and representative benchmarks show a useful end-to-end RPM improvement.
- Use only algorithms returned by `hash_hmac_algos()`.
- Do not attempt to use xxHash, CRC, MurmurHash or another non-cryptographic hash with HMAC.
- Do not accept a user-controlled HMAC algorithm name directly.
- Resolve and validate configurable algorithm names once at configuration or bootstrap time rather than repeatedly in a hot path.
- Generate new HMAC keys from a cryptographically secure source such as `random_bytes()`.
- Do not use predictable identifiers, timestamps or low-entropy passwords directly as HMAC keys.
- Use an appropriate key-derivation function when an HMAC key must be derived from password-like material.
- Keep HMAC keys separate by purpose.
- Use explicit domain separation, protocol versioning or context prefixes when one key could otherwise authenticate several message types.
- Avoid ambiguous field concatenation.
- Canonicalize or length-prefix structured fields before computing the HMAC.
- Compute the HMAC over the exact bytes defined by the protocol.
- Prefer raw binary HMAC output for internal processing when contracts permit:

```php
$mac = hash_hmac('sha256', $payload, $secretKey, true);
```

- Encode the HMAC once at the external boundary when a textual signature is required.
- Compare received and expected MAC values with `hash_equals()`.
- Pass the trusted expected value as the first argument and the user-provided value as the second argument.
- Compare values in the same encoding and expected length.
- Do not compare secret MAC values with `==` or `===`.
- Do not truncate an HMAC unless an established protocol requires it or a documented security analysis approves the tag length.
- HMAC authenticates data; it does not encrypt or hide the message.
- Use incremental HMAC or file-HMAC APIs for large streams and files instead of materializing the complete input.
- Avoid recalculating the same HMAC more than once in one operation.
- Benchmark HMAC independently from plain hashing because key setup, inner and outer hashing and payload size can change the relative result.

### OTP, Passwords And Randomness

- Preserve the algorithm required by the OTP protocol and provisioning contract.
- HOTP requires the protocol-defined HMAC-SHA-1 construction unless an explicitly compatible extension defines otherwise.
- TOTP may use HMAC-SHA-1, HMAC-SHA-256 or HMAC-SHA-512 when the selected algorithm is consistently provisioned and supported by both generator and verifier.
- Do not automatically replace HMAC-SHA-1 in HOTP or an existing TOTP deployment merely because another algorithm benchmarks faster.
- Treat the moving factor as the exact protocol-defined binary value, not its printable hexadecimal text.
- Decode Base32, hexadecimal or other secret encodings once per operation or once per safely scoped immutable instance.
- Reuse the decoded binary secret inside repeated verification-window checks.
- Keep verification windows and resynchronization ranges no larger than correctness and usability require because each additional candidate requires another HMAC operation.
- Preserve throttling, replay protection, time-step behavior, counter behavior and timing-safe comparison while optimizing OTP throughput.
- Benchmark:
  - HOTP generation,
  - HOTP verification,
  - TOTP generation,
  - TOTP verification,
  - valid and invalid codes,
  - each supported HMAC algorithm,
  - realistic verification windows,
  - and repeated calls inside one request.
- Validate implementations against published protocol test vectors before accepting performance results.

- Do not use fast general-purpose hashes or HMAC as a replacement for password hashing.
- Use `password_hash()` and `password_verify()` or another approved password-hashing API.
- Prefer the project's approved memory-hard password algorithm where available and compatible.
- Tune password-hashing cost separately from general request-path hashing.
- Password hashing is intentionally expensive; do not weaken its cost merely to improve benchmark RPM.
- Capacity-plan authentication endpoints around the selected password-hashing cost.
- Apply rate limiting and abuse controls rather than replacing password hashing with a faster digest.
- For already high-entropy random API tokens, choose storage and verification strategy from the threat model; do not automatically apply password-hashing cost intended for human passwords.
- Never log plaintext passwords, HMAC keys, OTP secrets, raw API tokens or derived secret material.

- Use `random_bytes()` or `random_int()` for security-sensitive random values.
- A default `Random\Randomizer` uses a cryptographically secure engine, but an injected engine may not.
- Do not accept arbitrary random engines for security-sensitive identifiers, tokens, secrets, nonces or cryptographic operations.
- Use deterministic or seedable engines only for tests, simulations, fixtures or explicitly non-security-sensitive behavior.
- Reuse a safely scoped `Random\Randomizer` when repeated operations justify it.
- Do not instantiate random engines repeatedly inside hot loops without measurement.
- Use `Randomizer::getBytesFromString()` only when uniform selection from an explicit byte alphabet satisfies the contract.
- Do not confuse random-looking output with sufficient entropy.
- Calculate entropy from the alphabet size and generated length.

- Do not rely blindly on runtime-default password-hashing cost values because defaults can change between PHP versions.
- Define and benchmark password-hashing policy explicitly for each deployment class.
- For bcrypt, set an intentional cost when stable authentication capacity is required.
- For Argon2, set intentional memory, time and thread parameters where supported.
- Keep password-hashing policy strong enough for the threat model even when authentication RPM is lower.
- Use `password_needs_rehash()` when the selected algorithm or options change.
- Rehash after successful verification rather than through an unbounded bulk operation.
- Include the selected password algorithm and options in authentication capacity tests.
- Treat a runtime upgrade that changes hashing defaults as a capacity-affecting change requiring measurement.

### Cryptographic Benchmarking

- Maintain separate benchmarks for:
  - short scalar payloads,
  - common request-sized payloads,
  - large buffers,
  - streaming input,
  - raw binary output,
  - encoded output,
  - one-shot hashing,
  - repeated hashing,
  - one-shot HMAC,
  - repeated HMAC with the same algorithm and key,
  - file hashing,
  - and end-to-end request throughput.
- Include call overhead and output encoding when they occur in production.
- Benchmark the actual payload-size distribution; large-buffer gigabytes-per-second results may not predict performance for 16-byte, 64-byte or 1-KiB messages.
- Warm the runtime consistently and run enough iterations to separate algorithm cost from timer and function-call noise.
- Confirm every candidate produces the required digest width, encoding and protocol-compatible result.
- Measure collision-handling cost for non-cryptographic hashes where collisions are possible.
- Record algorithm availability with `hash_algos()` and `hash_hmac_algos()` for the supported PHP runtime.
- Do not add runtime algorithm discovery to every request.
- Prefer build-time or startup validation of required algorithms.
- Select the fastest measured algorithm that satisfies the exact security, collision, interoperability and output requirements.

## Database, External Services And Caching

### Database Access And Storage

- Apply these rules to applications, database libraries, query builders, repositories, storage adapters and ORM layers.
- Avoid hidden queries, implicit metadata discovery, unnecessary automatic count queries and per-row object construction.
- Keep connection acquisition, statement preparation, parameter binding, execution and result conversion explicit enough to profile.
- Support prepared-statement reuse, batching, streaming and cursors when they increase complete workload RPM.
- Do not optimize the PHP database layer in a way that overloads the database or lowers total successful RPM.
- Treat query count, rows examined, rows returned, transferred bytes, lock duration and transaction duration as measurable costs.
- Batch inserts, updates, deletes and lookups where correctness permits.
- Select only required columns.
- Avoid loading complete records when only existence, count, aggregate or identifier data is needed.
- Prefer existence checks over full counts when only existence is required.
- Push appropriate filtering, joining, grouping and aggregation to the database.
- Do not transfer excessive data into PHP for work the database can perform efficiently.
- Do not move business rules into SQL when doing so materially harms correctness or maintainability.
- Verify important queries with execution-plan tooling such as `EXPLAIN` or `EXPLAIN ANALYZE`.
- Test plans using representative data volume and distribution.
- Do not infer production behavior from a small development dataset.
- Add indexes based on actual predicates, joins ordering, uniqueness requirements and measured query plans.
- Do not add speculative indexes.
- Account for index storage and write amplification.
- Avoid functions, casts and transformations on indexed columns when they prevent useful index access.
- Keep transactions as short as correctness permits.
- Avoid network calls, file operations, user interaction or slow computation inside transactions.
- Establish a consistent lock order when multiple resources are updated.
- Use keyset or cursor pagination for large or frequently changing datasets where offset pagination becomes costly or inconsistent.
- Use offset pagination when datasets are bounded and simplicity is the better trade-off.
- Choose buffered or unbuffered query modes deliberately.
- Account for connection occupancy and driver limitations with unbuffered results.
- Keep bulk operations within safe parameter, packet, lock and transaction-size limits.
- Avoid exact total-count queries when the caller does not require an exact total.

**PDO And Database-Extension Compatibility**

- Prefer driver-specific PDO subclasses on supported runtimes when driver-specific constants or methods are required.
- Keep portable behavior on standard PDO contracts and isolate genuinely driver-specific behavior.
- Do not call driver-specific APIs through the base `PDO` class when the supported runtime provides a driver-specific class.
- Treat database extensions, native clients and driver versions as explicit deployment dependencies.
- Declare, install and verify required database extensions.
- Include PDO driver, native client and server versions in reproducible benchmark evidence.

### External I/O And Concurrency

- Set explicit connection, read, write and total-operation timeouts for external dependencies.
- Do not allow a slow dependency to consume request or worker capacity indefinitely.
- Batch independent remote operations when supported.
- Use bounded concurrency when it improves latency and the runtime supports it safely.
- Never introduce unbounded parallelism.
- Respect downstream connection pools, rate limits, quotas and backpressure.
- Retry only operations that are safe to retry.
- Keep retry counts bounded.
- Use appropriate delay and jitter.
- Make retried write operations idempotent or protect them with an idempotency mechanism.
- Do not retry deterministic validation, authorization or permanent client errors.
- Avoid retry multiplication across multiple infrastructure layers.
- Use queues, deferred processing, circuit breaking or load shedding only when they meaningfully reduce latency or cascading failure.
- Do not introduce asynchronous processing when synchronous execution already meets latency and reliability requirements.

**Attached Resources And Service Boundaries**

- Treat databases, caches, queues, object storage, SMTP providers and external APIs as attached resources.
- Access each resource through explicit configuration, credentials and resource handles.
- Do not hard-code whether a resource is local, self-managed, cloud-managed or third-party.
- Allow a compatible resource instance to be replaced through configuration without changing business logic.
- Treat each resource as having independent:
  - health state,
  - timeouts,
  - rate limits,
  - connection limits,
  - metrics,
  - and capacity budgets.
- Do not silently fall back from a required shared resource to process memory or local disk when correctness or cross-worker behavior changes.
- Keep provider-specific behavior behind a boundary only when substitution is a real operational requirement.
- Do not hide important transactional, consistency, latency or capability differences behind a generic abstraction.
- Include backing-service latency and saturation in sustained RPM tests.

**HTTP Client Interoperability**

- Use PSR-18 `ClientInterface` at reusable outbound HTTP boundaries where the caller must select the client implementation.
- Use the narrow PSR-17 request, URI and stream factories required to construct PSR-7 requests without coupling to one implementation.
- Reusable libraries should depend on the PSR interfaces rather than a concrete HTTP client when interoperability is part of the contract.
- Applications may use a concrete client directly when no substitution boundary exists and the simpler design is preferable.
- Do not assume PSR-18 throws for HTTP `4xx` or `5xx` responses; inspect response status explicitly.
- Treat PSR-18 client and network exceptions according to their defined interfaces.
- Keep timeout, retry, redirect, authentication and circuit-breaking policy in the appropriate integration layer.
- Do not assume PSR-18 provides asynchronous or concurrent requests.
- Use implementation-specific asynchronous APIs only behind an explicit boundary and only when measured throughput justifies them.
- Avoid converting request and response objects between several HTTP abstractions.
- Apply adapters once at the boundary and benchmark their effect on sustained RPM.

### Caching

- Add caching only after identifying a meaningful repeated cost.
- Cache the smallest stable result at the most appropriate layer.
- Define cache keys, scope, ownership, expiration, invalidation and versioning explicitly.
- Do not treat cache invalidation as an implementation detail.
- Prevent cache stampedes for expensive or highly shared entries.
- Use bounded cache sizes and appropriate TTLs.
- Avoid indefinite process-local caches in long-running workers unless growth is strictly bounded.
- Do not cache authorization decisions, secrets, failures, empty results or user-specific data without considering security and staleness.
- Do not use caching to conceal inefficient queries or broken access patterns.
- Measure cache hit rate, miss cost, latency, memory use and invalidation behavior.
- Ensure the system remains correct when the cache is cold, unavailable, stale or cleared.

**Cache Interoperability**

- Use PSR-16 `Psr\SimpleCache\CacheInterface` for simple key/value caching when its `null`-on-miss semantics are acceptable.
- Use PSR-6 `Psr\Cache\CacheItemPoolInterface` when the contract needs explicit hit detection, cache items, deferred saves or richer pool semantics.
- Do not depend on both PSR-6 and PSR-16 unless the package intentionally supports both contracts.
- Do not add a PSR cache dependency when caching is entirely internal and no implementation substitution is required.
- Reusable cache-aware libraries should accept a PSR cache interface from the caller rather than instantiate a concrete cache provider.
- Applications must provide and configure the actual cache implementation.
- With PSR-16, do not cache `null` when the caller must distinguish a stored `null` from a miss unless an explicit sentinel or envelope is used.
- With PSR-6, call `isHit()` when a cached `null` value must be distinguished from a miss.
- Use multi-key methods when they reduce network round trips and preserve semantics.
- For PSR-6 deferred writes, call `commit()` at an explicit safe boundary; do not rely solely on destructors for critical persistence.
- Keep keys compatible with the selected PSR and backing store.
- Avoid layered PSR-6/PSR-16 adapters in hot paths unless interoperability value outweighs conversion cost.
- Benchmark direct provider access and PSR adapter access when cache calls dominate request throughput.

## Events, Logging And Observability

### Events And Extension Points

- Use direct method calls for required synchronous behavior when the caller knows the collaborator.
- Use PSR-14 when a reusable package or application exposes a genuine interoperable event-dispatch boundary.
- Do not introduce events merely to avoid an explicit dependency or direct call.
- Keep event objects immutable when listeners only need notification.
- Allow controlled mutation only when listeners are intentionally expected to contribute information back to the emitter.
- Treat PSR-14 dispatch as synchronous.
- Do not assume listener return values are used.
- Allow listener failures to propagate according to the PSR-14 contract unless an explicit boundary converts or records them and rethrows appropriately.
- Use stoppable events only when early termination is a real part of the contract.
- Do not hide mandatory authorization, validation, transaction or persistence flow behind optional listeners.
- Compile listener mappings before runtime when possible.
- Avoid reflection-based listener discovery on every request.
- Keep listener order explicit when behavior depends on ordering.
- Do not emit high-volume events inside hot loops without representative throughput measurements.
- Queue or defer listener work only when the result is not required by the emitter and delivery semantics are explicitly defined.
- Measure dispatcher, listener-provider and listener costs as part of end-to-end RPM.

### Logging And Diagnostic Cost

- Avoid expensive debug formatting when the corresponding log level is disabled.
- Do not log complete large payloads, binary data, secrets, credentials, tokens or sensitive information.
- Use structured logs with bounded field sizes.
- Include a release, build or commit identifier in logs and metrics when available.
- Sample high-volume diagnostic events where full capture is unnecessary.
- Keep logging, tracing and metrics lightweight on hot paths.
- Record durations, query counts, external calls, failures and relevant payload sizes where they aid diagnosis.

**Logs As Event Streams**

- Treat logs as event streams rather than application-managed files.
- Write normal process output to `stdout` and errors to `stderr` when the execution environment captures those streams.
- Do not make application code responsible for log rotation, retention, routing, compression or archival.
- Let the execution environment route and retain logs.
- Include stable fields where available:
  - timestamp,
  - severity,
  - event name,
  - release or build identifier,
  - process type,
  - request or correlation identifier,
  - and bounded relevant context.
- Avoid synchronous remote log transport in request paths.
- Do not suppress errors solely to improve benchmark throughput.
- Use the logging platform for aggregation, searching, alerting and RPM/error analysis.

**Logging Interoperability**

- Use PSR-3 `LoggerInterface` at reusable package and infrastructure boundaries when caller-controlled logging is useful.
- Libraries should accept a caller-provided logger, remain logging-agnostic or use an explicitly configured no-op logger.
- Do not instantiate or configure a concrete logging backend inside a reusable library.
- Use stable message templates and place variable data in the context array.
- Put a caught `Throwable` in the `exception` context key when the logger should record it.
- Keep context values tolerant of mixed types and bounded in size.
- Do not construct expensive context merely because a `NullLogger` or disabled backend will discard it.
- Guard expensive diagnostic context generation or expose lazy caller-controlled diagnostics.
- Do not log once per item in large loops unless the operational requirement outweighs the RPM cost.
- When adapting a framework logger to PSR-3, perform the adaptation once rather than wrapping every log call.
- Select a `psr/log` package major version compatible with the project's PHP baseline and logger implementation.

## Dependencies, Configuration And Code Loading

### Composer, Dependencies And Autoloading

- Use Composer autoloading instead of manual class includes.
- Use PSR-4-compatible, case-correct namespaces and paths.
- Treat path and casing violations as build failures.
- Use paths anchored with `__DIR__` for required non-class files.
- Avoid current-working-directory-dependent loading.
- Avoid mutable `include_path` behavior.
- Avoid request-derived or uncontrolled include paths.
- Do not repeatedly call `file_exists()`, `is_file()` or `is_readable()` before loading known application classes.
- Avoid recursive directory scanning, reflection discovery and filesystem registration in production request paths.
- Perform discovery during build, deployment or cache-generation stages.
- Generate Composer’s optimized classmap for every production build:

```bash
composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction
```

- Use authoritative classmaps when the complete production class set is known at deployment time.
- Do not enable authoritative classmaps when the application or a dependency legitimately generates classes dynamically.
- When authoritative mode is unsuitable, retain optimized classmaps and consider APCu autoload-miss caching only after measurement.
- Do not combine authoritative classmap mode with APCu autoload optimization.
- Rebuild the production autoloader whenever source files or dependencies change.
- Never generate Composer autoload metadata during a web request.

**Dependency Declaration And Isolation**

- Declare PHP dependencies explicitly in `composer.json`.
- Commit `composer.lock` for deployable applications.
- For reusable libraries, define compatible dependency ranges deliberately and test the supported range.
- Declare required PHP versions and extensions through Composer platform requirements where practical.
- Do not rely on globally installed packages, incidental `include_path` entries, undeclared extensions or developer-machine tools.
- Treat external executables and system libraries as explicit dependencies.
- Declare, package or provision required system tools and compatible versions.
- Fail build or startup validation clearly when a required dependency is unavailable.
- Exclude development-only dependencies from production release artifacts.
- Do not add request-path dependency-isolation wrappers when build and process isolation solve the requirement.

**Interoperability Package Dependencies**

- A reusable consumer library should require only the PSR interface packages it actually uses.
- Do not require a concrete PSR implementation from a reusable consumer library unless the implementation is an intentional part of its contract.
- A deployable application must install and configure concrete implementations for every consumed PSR contract.
- A package that implements a PSR should declare the appropriate implementation capability through Composer when the ecosystem contract defines one.
- Keep PSR package major versions compatible with the project's supported PHP versions and the selected implementation.
- Do not install PSR packages speculatively.
- Avoid adapter packages that convert among several equivalent contracts unless integration requirements justify the added calls and allocations.
- Test public interoperability against at least one independent implementation when the package claims framework-agnostic support.

### Configuration And Generated Metadata

- Compile stable configuration, routes, dependency mappings, event mappings, serializer metadata and similar discovery-heavy structures during build or deployment.
- Prefer deterministic generated PHP files over reparsing YAML, XML, JSON, `.env`, annotations or directory structures on every request.
- Do not parse `.env` files in production request paths.
- Load production configuration from the process environment or deployment-generated immutable configuration.
- Do not regenerate compiled metadata from a normal application request.
- Version generated metadata with the application release.
- Write generated files atomically before exposing them to workers.
- Validate generated PHP files before activating a release.

**Deploy Configuration And Secrets**

- Keep configuration that varies between deploys outside the codebase.
- Inject database, cache, queue, service, hostname, credential, feature-control and per-deploy settings through environment variables or an equivalent deployment-controlled mechanism.
- Never commit secrets, private keys, OTP seeds, HMAC keys, credentials or production resource locators.
- Keep non-varying internal configuration in version-controlled code or build-generated immutable metadata.
- Prefer granular configuration values over scattered conditionals based on broad names such as `development`, `staging` or `production`.
- Validate and normalize required configuration once during build validation, startup or bootstrap.
- Reuse the immutable normalized result.
- Do not repeatedly read `getenv()`, `$_ENV`, secret stores or config parsers inside hot paths.
- Reusable libraries should receive deploy-varying configuration from callers unless environment loading is their explicit responsibility.
- Keep sensitive configuration out of logs, exceptions, diagnostics and benchmark output.
- Design the repository so it can be published without revealing deploy credentials.

### OPcache-Friendly Loading And Filesystem Resolution

- Write PHP that can be loaded from stable, deterministic files and reused unchanged across requests.
- Treat OPcache compatibility as part of code structure, autoloading, build generation and deployment design.
- Do not distort clear architecture for speculative OPcache micro-optimizations.
- Avoid `eval()`, runtime source generation and dynamically generated PHP classes unless technically necessary and measured.
- Do not generate, rewrite or modify application PHP source files during normal request processing.
- Keep deployed PHP source immutable for the lifetime of a release.

- Use stable, canonical paths.
- Size PHP’s realpath cache according to actual file and path usage.
- Use longer realpath cache TTLs only with immutable or rarely changing deployments.
- Account for security settings that disable realpath caching.
- Do not weaken required security boundaries merely to enable path caching.
- Avoid unnecessary repeated filesystem checks even when realpath caching is enabled.

## Runtime Processes, Deployment And Operations

### Runtime Model And Process Management

- Choose the PHP runtime model by measured sustained successful RPM, not convention.
- Benchmark supported execution models where relevant, including:
  - PHP-FPM,
  - persistent-worker application servers,
  - and other production-supported SAPIs.
- Prefer the execution model with the highest stable successful RPM for the representative workload.
- Persistent workers may remove repeated bootstrap and autoload cost, but use them only when:
  - request-specific state is reliably reset,
  - memory growth is bounded,
  - services do not retain tenant or user state,
  - database and network resources are managed safely,
  - and soak tests confirm stability.
- Do not adopt persistent workers solely because a synthetic Hello World benchmark is faster.
- Size worker and process counts from a measured throughput curve.
- Increase concurrency until throughput stops improving or errors, queueing, context switching, database pressure or tail latency rise materially.
- Do not assume one worker per CPU core is universally optimal.
- Align PHP worker capacity with:
  - database connection limits,
  - cache connections,
  - external-service limits,
  - available memory,
  - and CPU saturation.
- Prefer connection reuse and keep-alive where they improve RPM and remain operationally safe.
- Avoid excessive persistent connections that reduce database availability or worker density.
- Offload static files and large immutable assets from PHP when a web server or CDN can serve them more efficiently.
- Apply response compression only when its network savings improve end-to-end RPM.
- Use queues for work that is not required to complete the response when deferral materially increases request throughput and preserves correctness.
- Keep request handlers free from non-essential background work.

**Stateless And Share-Nothing Processes**

- Design web and worker processes so correctness does not depend on memory or local filesystem state surviving a request, restart or reschedule.
- Persist durable state in appropriate backing services.
- Treat process memory and local filesystem storage as ephemeral.
- Allow process-local caches only when correctness does not depend on future hits, entries are bounded and any process may handle the next request.
- Do not rely on sticky sessions.
- Store shared session state in a shared backing service.
- Do not store uploads, exports or business records only on a worker's local filesystem.
- Persistent workers must reset request-, tenant- and user-specific state after every request.
- Test worker replacement, process restart and multi-worker routing.

**Service Endpoints And Port Binding**

- Bind self-contained services to an explicitly configured host, port or socket.
- Do not hard-code listening ports, public hostnames, TLS termination or routing topology.
- For PHP-FPM, treat the configured FastCGI socket or port as the process endpoint and keep the web server separately deployable.
- Do not force reusable libraries to open or manage listening ports.
- Keep health and readiness endpoints inexpensive and free of sensitive topology details.

**Process Types And Concurrency**

- Represent workloads with different scaling, latency, resource or failure characteristics as explicit process types.
- Separate web request handling from long-running background work when synchronous completion is not required.
- Scale horizontally through stateless process instances when measured capacity requires it.
- Size web workers, queue consumers, schedulers and administrative processes independently.
- Keep concurrency bounded by database, cache, queue and downstream capacity.
- Do not daemonize or manage PID files inside application code when a process manager, container runtime or orchestrator owns lifecycle.
- Let the process manager capture output, restart crashes and coordinate shutdown.
- Internal asynchronous concurrency is allowed when it increases sustained successful RPM and remains bounded and observable.
- Do not use process proliferation to conceal a downstream bottleneck.

**Disposability And Graceful Shutdown**

- Keep startup fast enough for deployment, recovery and horizontal scaling.
- Move expensive deterministic preparation into build or release stages.
- Handle termination signals and stop accepting new work before shutdown where supported.
- Allow bounded in-flight completion.
- Acknowledge only completed jobs and return unfinished work or leases safely.
- Design jobs to be idempotent or reentrant because termination can occur after partial execution.
- Assume sudden death can bypass destructors, shutdown handlers and `finally` blocks.
- Protect durable operations with transactions, idempotency keys, leases, checkpoints or deduplication.
- Test graceful shutdown, abrupt death and simultaneous worker startup.

**Runtime Limits And Diagnostics**

- Keep per-process memory limits aligned with worker count, container limits and required worker density.
- On supported runtimes, use `max_memory_limit` as an administrator-controlled ceiling when application code must not raise or disable its memory limit.
- Do not use `ini_set('memory_limit', '-1')` as a general performance tactic.
- Do not expose `phpinfo()` or unrestricted runtime configuration publicly.
- Record whether PHP is:
  - thread-safe or non-thread-safe,
  - debug or production built,
  - using JIT,
  - and using the intended OPcache configuration.

### Build, Release And Deployment

- Prefer immutable, versioned releases and atomic activation.
- Never partially overwrite PHP files that may be read concurrently.
- Complete dependency installation, autoloader generation, metadata compilation, validation and tests before activating a release.
- When timestamp validation is disabled, make OPcache refresh an explicit deployment step.
- Refresh OPcache through a controlled PHP-FPM reload, process restart or correctly scoped invalidation.
- Do not assume changed files will become visible when timestamp validation is disabled.
- Do not expose unrestricted OPcache reset or status endpoints publicly.
- Restrict cache controls to trusted deployment or administrative paths.
- Reduce file-update protection only when all PHP file updates are guaranteed to be atomic.
- Roll back by activating a complete previous release, not by restoring individual files inside the active release.

**Codebase And Release Identity**

- Keep each independently deployable application or service associated with one version-controlled codebase.
- Use the same codebase across development, staging and production; deploys may run different committed versions.
- Treat independently deployable components as separate applications with explicit contracts.
- Move genuinely shared code into versioned Composer packages instead of copying source between applications.
- Identify every release by an immutable commit, tag, build ID or release ID.
- Keep generated artifacts traceable to the source revision that produced them.

**Build, Release And Run Separation**

- Separate deployment into build, release and run stages.
- Build should:
  - install dependencies,
  - optimize Composer metadata,
  - compile stable routes, configuration and metadata,
  - build assets,
  - run validation,
  - and produce an immutable artifact.
- Release should combine an immutable build with deploy-specific configuration and assign a unique release ID.
- Run should start an existing release with as few moving parts as practical.
- Do not download code, update packages, discover dependencies, generate classes, compile source or mutate application files during normal requests.
- Treat releases as append-only and immutable.
- Any code, dependency or build-artifact change must produce a new release.
- Roll back by activating a complete previous release rather than patching live files.
- Verify release configuration and required resource connectivity before shifting traffic where practical.

### OPcache Capacity, Warm-Up And Observability

- Enable OPcache in production and verify it is active for the intended SAPI.
- Size OPcache from observed usage, not copied configuration.
- Size:
  - `opcache.memory_consumption`,
  - `opcache.max_accelerated_files`,
  - and `opcache.interned_strings_buffer`
  using measured application requirements.
- Keep sufficient headroom to prevent cache exhaustion and frequent restarts.
- Keep `opcache.max_file_size=0` unless excluding large files is supported by measurement.
- Retain safe optimizer passes.
- Do not enable undocumented or unsafe optimizer flags.
- Keep `opcache.save_comments=1` unless the complete dependency set is verified not to use runtime doc comments.
- Do not disable comments for an assumed memory improvement.
- Do not disable `opcache.use_cwd` unless path uniqueness and application isolation are guaranteed.
- Consider controlled warm-up when cold-start latency is operationally significant.
- Warm representative frequently used code, not every file blindly.
- Compare warm-up cost against the first-request and sustained-RPM benefit.
- Avoid warm-up stampedes when several workers start together.
- Do not call `opcache_compile_file()` from normal request paths.
- Treat preloading as optional and workload-dependent.
- Preload only stable, frequently used code after measurement.
- Require a process restart when preloaded code changes.
- Treat JIT separately from OPcache and enable it only when representative benchmarks show meaningful benefit.

- Monitor OPcache rather than assuming it is effective.
- Track:
  - cache hit rate,
  - cached and uncached script counts,
  - used and free shared memory,
  - interned-string usage,
  - wasted memory,
  - cache-full status,
  - out-of-memory restarts,
  - hash-table restarts,
  - and manual restarts.
- Alert on recurring exhaustion, frequent restarts or sustained hit-rate degradation.
- Verify after deployment that expected application and Composer files are cacheable.
- Compare cold and warm request performance separately.
- Do not claim an OPcache improvement based only on configuration changes.

### Development And Production Parity

- Keep development, test, staging and production as similar as practical in:
  - PHP major and minor version,
  - required extensions,
  - Composer install mode,
  - runtime model,
  - OPcache behavior,
  - operating-system assumptions,
  - database type and major version,
  - cache and queue technology,
  - serialization formats,
  - and critical configuration semantics.
- Avoid lightweight local substitutes when behavioral differences can hide production defects.
- Use reproducible environments or declarative provisioning to reduce drift.
- Keep the time between verified change and deployment short enough to limit divergence.
- Test performance-sensitive code with production-equivalent PHP, Composer and OPcache settings.
- Test database behavior with representative production-like volume and distribution.
- Document unavoidable differences and test the affected boundaries.
- Keep developers close to deployment and production observability for the code they change.

### Administrative And One-Off Processes

- Run migrations, repair scripts, backfills, consoles and maintenance tasks as explicit one-off processes.
- Execute them against the same immutable release, dependency set and deploy configuration as regular processes.
- Keep administrative scripts version-controlled.
- Do not edit production code interactively or maintain untracked production-only scripts.
- Make administrative commands:
  - access-controlled,
  - auditable,
  - observable,
  - resumable where practical,
  - and safely rerunnable where feasible.
- Use bounded batches, checkpoints, transactions and idempotency for large changes.
- Do not let one-off tasks monopolize database connections, locks, CPU, memory or queue capacity required by request-serving processes.
- Benchmark or rate-limit heavy backfills when they can reduce production RPM.
- Require dry-run support or explicit confirmation for destructive operations.

## Tooling And Workflow

- Respect repository tooling and quality checks such as formatting, static analysis, refactoring, tests, mutation testing, profiling and benchmarks.
- Parallelize independent read-only tools at the orchestration layer; do not launch duplicate or nested copies of the same checker merely to increase concurrency.
- Keep source-mutating processors sequential unless disjoint ownership and atomic publication are proven.
- Bound task concurrency and diagnostic output, let every started peer finish, and render results in deterministic declaration order.
- Reuse one successful analyzer execution for both gating and machine-readable reporting when the tool can produce both results.
- Use the project’s existing automation instead of manually enforcing style.
- Configure automated formatting for the declared PER Coding Style or established repository standard.
- Keep formatting checks separate from semantic and performance validation.
- Use static analysis at the strongest practical level supported by the project.
- Treat findings from tests, static analysis, formatters, linters, mutation testing, security scanners, compatibility checks, complexity checks, coverage gates, performance gates and other quality detectors as issues to resolve at their root cause.

**Agent Execution, Tool And Token Efficiency**

- Treat agent token consumption, context size, tool calls and execution time as engineering resources.
- Reduce them without reducing:
  - correctness,
  - security,
  - source understanding,
  - verification depth,
  - test coverage,
  - compatibility coverage,
  - performance evidence,
  - or solution quality.
- Prefer deterministic local tooling over token-heavy manual inspection or reasoning when it provides equivalent or better evidence.
- Use the cheapest authoritative mechanism that can answer the question correctly.
- Prefer, where applicable:
  1. PHPForge built-in commands and detectors available in the installed project version,
  2. existing project scripts and repository automation,
  3. native PHP and Composer commands,
  4. Git and established system commands,
  5. targeted source inspection,
  6. small task-specific scripts,
  7. broad manual reconstruction or speculative reasoning.
- This order is a workflow preference, not permission to skip required inspection or verification.
- Use a lower-ranked mechanism when a higher-ranked tool:
  - cannot answer the question,
  - lacks required context,
  - requires independent verification,
  - or would make the task less correct or less efficient.
- Search before reading broadly.
- Inspect before designing.
- Reuse before creating.
- Edit before rewriting.
- Verify before reporting.

**PHPForge Command Preference**

- Before performing repetitive or repository-wide PHP analysis manually, determine whether the installed PHPForge version already provides an equivalent command, detector or workflow.
- Prefer PHPForge built-in capabilities for supported operations such as:
  - structural inspection,
  - class, file and abstraction review,
  - cognitive-complexity analysis,
  - dependency analysis,
  - documentation and PHPDoc checks,
  - comment-policy checks,
  - code-quality checks,
  - compatibility checks,
  - validation,
  - refactoring support,
  - profiling or benchmark support,
  - and other project-defined PHPForge operations.
- Treat the installed project's PHPForge version and its command help or documentation as the source of truth.
- Do not assume a PHPForge command, option, detector or behavior exists without checking the installed version when uncertain.
- Prefer one PHPForge operation that provides the required evidence over several manual repository scans or custom parsing passes.
- Do not duplicate PHPForge analysis with a custom implementation unless:
  - PHPForge does not support the required operation,
  - its result is insufficient for the active task,
  - a narrowly scoped alternative is materially cheaper while remaining equally authoritative,
  - or independent verification is required.
- Do not replace or bypass a PHPForge detector merely because another implementation is easier for the agent to interpret.
- Resolve PHPForge findings according to the quality-gate rules.
- Use PHPForge's documented automation and [AGENTS.md](vendor/infocyph/phpforge/resources/AGENTS.md) workflow when applicable.

**Repository And System Command Preference**

- Prefer existing repository commands and native tools for deterministic repository and runtime questions.
- Prefer Git for:
  - changed files,
  - diffs,
  - history,
  - blame,
  - tracked-file discovery,
  - and change scope.
- Prefer fast repository search tools for:
  - symbol lookup,
  - text lookup,
  - caller discovery,
  - configuration references,
  - and duplicate-pattern searches.
- Prefer filesystem commands for:
  - bounded file discovery,
  - file counts,
  - path inspection,
  - and metadata.
- Prefer PHP commands for:
  - syntax validation,
  - runtime capability checks,
  - extension checks,
  - INI inspection,
  - and deterministic PHP-level calculations.
- Prefer Composer commands for:
  - dependency information,
  - platform requirements,
  - autoload information,
  - package versions,
  - and dependency relationships.
- Prefer the configured formatter, static analyzer, test runner, mutation tool, security scanner, benchmark and profiler over manually approximating the same result.
- Do not create a temporary custom script for an operation already performed correctly by PHPForge, repository tooling, PHP, Composer, Git or an established system command.
- When a small custom script is still the most efficient correct option:
  - keep it narrowly scoped,
  - do not make it production architecture unless required,
  - and remove temporary analysis artifacts when the task is complete.

**Search And Inspection Efficiency**

- Prefer the smallest sufficient context needed for a correct decision.
- Identify likely files, symbols, callers, tests and configuration before reading broad portions of the repository.
- Prefer targeted:
  - symbol lookups,
  - reference searches,
  - file ranges,
  - diffs,
  - failing diagnostics,
  - test files,
  - configuration sections,
  - and command summaries
  over repeatedly loading complete files or directories.
- Read a complete file when full context is necessary for ownership, lifecycle, side effects, security, public contracts, concurrency or architectural decisions.
- Do not repeatedly reread unchanged content already inspected during the same task unless:
  - the file changed,
  - earlier context is insufficient,
  - or verification requires the exact current content.
- Reuse established facts and decisions from the current task instead of rediscovering them.
- Revalidate a fact when the relevant source or runtime state changed or when relying on the earlier result creates correctness risk.
- Avoid recursively exploring unrelated directories, dependencies or architectural alternatives without evidence that they affect the active task.
- Stop investigating an already resolved branch when additional inspection cannot materially change the implementation or verification decision.
- Do not use context minimization to skip relevant callers, implementations, contracts or side effects.

**Command And Tool Output Discipline**

- Keep command output proportional to the question being answered.
- Prefer summaries, changed files, matching lines, failing checks, relevant diagnostics, machine-readable output and bounded result sets over unrestricted verbose output.
- Filter large output only when omitted information cannot affect the engineering decision.
- Do not truncate, filter or summarize output in a way that could hide:
  - failures,
  - security findings,
  - compatibility issues,
  - affected callers,
  - test failures,
  - performance regressions,
  - or required context.
- Do not repeatedly run an expensive unchanged command unless:
  - relevant code changed,
  - the previous result was inconclusive,
  - state may have changed,
  - or final verification requires another run.
- Batch related independent searches or checks when doing so reduces repeated setup and context without hiding failures.
- Prefer one well-scoped command over several speculative commands.

**Implementation Efficiency**

- Prefer the smallest correct diff.
- Modify the existing cohesive owner instead of creating a new file or abstraction unless the structural rules justify one.
- Prefer targeted edits over whole-file rewrites when surrounding content does not need to change.
- Do not generate several complete competing implementations when repository constraints already determine the appropriate approach.
- Narrow alternatives using existing contracts, project conventions, supported runtimes, measured constraints and current architecture before designing multiple solutions.
- Check for an existing owner, utility, PHPForge capability, repository helper or contract before creating a new implementation.
- Do not reproduce unchanged code merely to show context.
- Keep intermediate plans proportional to task complexity.
- Proceed directly after obtaining sufficient context for simple, low-risk changes.
- Use more detailed planning when the task materially affects architecture, public contracts, security, data migration, concurrency, persistence, performance-sensitive paths or coordinated multi-file behavior.

**Reasoning And Context Efficiency**

- Use agent reasoning for trade-offs, architecture, ownership, semantics, security implications and decisions that require judgment.
- Use tools for facts that tools can determine directly.
- Do not spend reasoning tokens reconstructing information that can be obtained authoritatively from PHPForge, the repository, Git, PHP, Composer, configured analyzers, configured tests, runtime diagnostics or benchmark tooling.
- Summarize large intermediate findings compactly and retain only details required for subsequent decisions.
- Preserve exact source details when implementation correctness depends on them.
- Do not replace necessary source inspection with an approximate summary.
- Avoid repeatedly restating the user's requirements, the same implementation plan, unchanged code, complete file contents, complete command output or conclusions already established in the same task.

**Verification Efficiency**

- During implementation, run the narrowest relevant checks that provide useful feedback.
- After implementation, run every required broader validation defined by:
  - the repository,
  - PHPForge,
  - [AGENTS.md](vendor/infocyph/phpforge/resources/AGENTS.md),
  - CI configuration,
  - or the active task.
- Prefer targeted tests and detectors while iterating, then run the required complete validation before completion.
- Do not substitute targeted checks for mandatory final checks merely to save tokens, time, context or compute.
- Do not skip a required detector because another detector appears to cover similar behavior.
- Do not rerun an unchanged expensive validation after every trivial edit when a narrower deterministic check can safely guide iteration.
- Rerun affected validation after relevant changes and perform required final validation before reporting completion.

**Response Efficiency**

- Keep final responses concise, complete and decision-oriented.
- Report:
  - what changed,
  - important engineering decisions,
  - verification performed,
  - unresolved risks,
  - and anything that could not be verified.
- Do not reproduce unchanged files, large command outputs, repeated requirements, source already available to the user or unnecessary internal reasoning unless it materially helps review, use or maintenance.
- Include code, commands, diagnostics and explanation when they are necessary to understand or verify the solution.

**Efficiency Safety Rule**

- Token, context, command and execution efficiency must never be achieved by:
  - guessing instead of inspecting,
  - assuming instead of verifying,
  - skipping relevant files or callers,
  - skipping required tests,
  - weakening quality detectors,
  - reducing security analysis,
  - omitting required edge cases,
  - ignoring public contracts,
  - avoiding necessary benchmarks,
  - hiding command failures,
  - or returning an incomplete solution.
- When two workflows provide equivalent correctness, verification and solution quality, prefer the one with fewer tokens, less repeated context, fewer redundant reads, fewer redundant tool calls, less unnecessary output, fewer repository scans, less custom processing and shorter execution time.

**Quality-Gate Issue Resolution**

- Fix the code, configuration, test or contract that causes a valid finding.
- When a domain-specific section prohibits suppression, baselines, exclusions or exemptions more strictly, the stricter domain rule applies.
- The false-positive exception below does not override an explicit no-suppression rule elsewhere in this document.
- Do not make a failing quality gate pass by:
  - weakening or disabling the detector,
  - reducing its configured scope,
  - increasing a threshold,
  - lowering a required analysis level,
  - removing or weakening an assertion,
  - deleting a relevant test,
  - excluding affected files, paths, symbols or rules,
  - adding or expanding a baseline,
  - adding suppressions, ignore directives, allowlists or exemptions,
  - marking a failure as expected without correcting its cause,
  - changing CI to skip the check,
  - or bypassing the gate manually.
- Do not move problematic code outside the detector's scan path merely to avoid a finding.
- Do not replace a precise rule with a broader or weaker rule solely to make existing code pass.
- Do not change production behavior, public contracts or security controls merely to satisfy a detector when the detector is misconfigured.
- When a finding appears incorrect:
  - reproduce it with the smallest relevant case,
  - verify the detector's documented semantics,
  - verify project configuration and supported runtime versions,
  - and correct the detector configuration only when evidence shows that the configuration itself is wrong.
- Treat detector configuration changes as code changes:
  - keep them narrowly scoped,
  - explain the reason,
  - review their effect on all existing checks,
  - and verify that they do not hide other valid findings.
- Do not introduce inline suppression for a false positive unless no correct code or configuration fix exists and explicit approval is given.
- Any approved suppression must be:
  - as narrow as possible,
  - tied to a specific detector and finding,
  - documented with the technical reason,
  - covered by tests where relevant,
  - and assigned a review or removal condition.
- Do not use a baseline to absorb new violations.
- Do not update an existing baseline as part of ordinary feature or bug-fix work.
- Resolve new findings before merging.
- When legacy violations already exist, ensure the change does not add to them and fix any touched violation within the active scope where practical.
- If a required detector cannot run, report the exact reason and do not claim the quality gate passed.
- A clean result is valid only when the intended detector, rules, scope and thresholds remain active.
- Run compatibility and deprecation checks against every supported PHP version and the next intended upgrade target.
- Add or update tests for affected behavior, boundaries, failures and contracts.
- Add benchmarks only for meaningful, stable, performance-sensitive behavior.
- Follow any additional mandatory post-implementation or documentation workflow in [AGENTS.md](vendor/infocyph/phpforge/resources/AGENTS.md) when that file exists.
- Review automated changes for correctness.
- Keep automated changes within scope.
- Do not accept generated or automated refactoring without reviewing the resulting behavior.
- Report which formatting, analysis, tests, profiling and benchmarks were run.
- Clearly state anything that could not be verified.
