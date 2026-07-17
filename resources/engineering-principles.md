# PHPForge Engineering Instructions

## Core Principles

- Stay framework-agnostic unless the project clearly requires otherwise.
- Prioritize, in order: correctness, security, performance, reliability, maintainability.
- Prefer simple, explicit, measurable solutions.
- Avoid speculative architecture, unnecessary abstractions and unnecessary layers.
- Follow project conventions unless they conflict with correctness, security or a clearly better design.
- Use modern PHP, relevant PHP-FIG standards, strict typing and clear contracts where appropriate (`PER`, `PSRs`).
- Apply `SOLID` pragmatically. Prefer composition over inheritance unless inheritance is clearly the better fit.
- Be skeptical of defaults, conventions and abstractions that introduce hidden runtime or operational costs.
- Choose the design with the best balance of speed, scalability, operational safety and maintainability.
- When uncertain, choose the simpler design that is easier to profile, test, operate and change.

## Scope And Change Discipline

- Focus on the active task and implement the smallest correct change set.
- Do not perform broad cleanup, refactoring, renaming, file moves, dependency changes or rewrites without explicit approval.
- Do not change behavior outside the requested scope unless required for correctness, security, compatibility or stability.
- Preserve public contracts unless a breaking change is explicitly approved.
- Do not introduce infrastructure, dependencies, patterns or abstractions for hypothetical future requirements.
- Separate required changes from optional improvements.
- Make meaningful trade-offs explicit.
- Do not hide unrelated refactoring inside feature or bug-fix work.

## Type Safety And Data Boundaries

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
- Use `isset()`, `array_key_exists()`, `empty()`, explicit comparisons and type predicates according to their exact semantics.
- Do not use null coalescing or null-safe access to conceal missing validation.
- Avoid undocumented sentinel values.
- Prefer explicit result objects, enums or exceptions when failure states require structured handling.

## Control Flow And Branching

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

## Code Design

- Keep methods, functions and closures small, cohesive and clear.
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

## Cognitive Complexity

Use the following default limits:

```yaml
cognitive_complexity:
    class: 80
    function: 12
    dependency_tree: 120
```

- Treat cognitive complexity as a maintainability guard, not a runtime-performance target.
- Do not increase complexity thresholds to pursue theoretical performance improvements.
- Prefer simplifying control flow before extracting additional methods or classes.
- Do not fragment cohesive hot-path logic into excessive wrappers solely to satisfy a complexity limit.
- Do not raise a project-wide limit to accommodate one parser, protocol implementation, state machine, generated file or performance-critical algorithm.
- Allow narrowly scoped exceptions only when profiling and representative benchmarks show that the simpler refactoring causes a material regression.
- Document every exception with:
  - the affected hot path,
  - profiler or benchmark evidence,
  - the tested workload,
  - why the complexity is necessary,
  - and when the exception should be reviewed.
- Exclude generated code from cognitive-complexity enforcement where appropriate.
- Configure dependency-tree analysis against relevant project root types instead of applying it blindly to every class.

## Iteration And Algorithmic Efficiency

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

## PHP Iteration Rules

- Prefer `foreach` for normal array and iterable traversal.
- Use `for` when numeric indexes, counters or bounded positional access are intrinsic to the algorithm.
- Use `while` when iteration is naturally condition-driven.
- Do not choose `for`, `foreach` or `while` based solely on generic syntax benchmarks.
- Do not convert associative arrays into key lists merely to iterate with `for`.
- Avoid iteration by reference unless actual in-place mutation is required.
- After a `foreach` by reference, explicitly unset the reference variable:

```php
foreach ($items as &$item) {
    $item->normalize();
}

unset($item);
```

- Avoid repeatedly evaluating non-trivial expressions in loop conditions.
- Precompute loop bounds when doing so removes meaningful work, stabilizes intended behavior or improves clarity.
- Do not cache trivial values by default when the benefit is unmeasured and the additional state reduces readability.

## Memory And Data Movement

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
- Release file handles, cursors, network resources and large temporary references promptly.
- Avoid unbounded in-memory accumulation in workers, consumers, daemons and long-running commands.
- Reset request-specific or job-specific state in long-running processes.
- Use worker recycling as a safety mechanism, not as a substitute for fixing memory growth.
- Do not increase memory limits to hide avoidable full-dataset loading or leaks.

## Database And Storage Performance

- Treat query count, rows examined, rows returned, transferred bytes, lock duration and transaction duration as measurable costs.
- Avoid N+1 query patterns.
- Do not rely on lazy loading in performance-sensitive paths without verifying the resulting query count.
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

## External I/O And Concurrency

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

## Caching

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

## Serialization, Logging and Output

- Avoid repeated serialization and deserialization of the same payload.
- Do not construct response fields the caller does not need.
- Bound internal and external payload sizes.
- Stream large exports and downloads instead of buffering the entire output.
- Avoid many tiny writes when output can be safely buffered or streamed more efficiently.
- Do not build one extremely large in-memory string merely to reduce output calls.
- Avoid expensive debug formatting when the corresponding log level is disabled.
- Do not log complete large payloads, binary data, secrets, credentials, tokens or sensitive information.
- Use structured logs with bounded field sizes.
- Sample high-volume diagnostic events where full capture is unnecessary.
- Keep logging, tracing and metrics lightweight on hot paths.
- Record durations, query counts, external calls, failures and relevant payload sizes where they aid diagnosis.

## Performance Measurement

- Measure before optimizing.
- Establish a baseline before making a performance change.
- State the performance hypothesis and the affected resource:
  - latency,
  - throughput,
  - CPU,
  - memory,
  - allocation,
  - query count,
  - I/O,
  - lock time,
  - or network transfer.
- Profile the complete request, command, job or workflow before focusing on isolated syntax.
- Benchmark representative workloads, data sizes, distributions, hit rates and failure paths.
- Separate microbenchmarks from end-to-end benchmarks.
- Use microbenchmarks for isolated implementation choices, not as proof of complete application performance.
- Run multiple iterations.
- Inspect variance, outliers and individual results.
- Reject conclusions when measurement variance is similar to or larger than the reported improvement.
- Warm relevant runtime state when production normally runs warm.
- Measure cold-start behavior separately when it matters.
- Use monotonic high-resolution timing for elapsed measurements.
- Measure peak memory, not only final memory.
- Ensure benchmark candidates produce identical observable results.
- Include setup and teardown costs when they exist in the real execution path.
- Exclude setup only when production genuinely amortizes or reuses it.
- Test scaling behavior across several input sizes.
- Control PHP version, extensions, OPcache state, dependencies, hardware, environment and datasets.
- Record benchmark commands and environment details.
- Do not benchmark with Xdebug enabled unless measuring Xdebug-enabled operation.
- Add performance regression checks only for stable, business-critical hot paths with reliable signals.
- Do not claim an improvement based on a single run.
- Remove complexity introduced for an optimization when the measured benefit is insignificant.

## Micro-Optimization Policy

- Optimize algorithms, queries, I/O, allocations, serialization and data movement before syntax-level details.
- Do not convert generic PHP microbenchmarks into universal coding rules.
- Treat benchmark results as specific to the tested runtime, configuration, hardware, input shape and workload.
- Do not assume meaningful gains from:
  - `for` versus `foreach`,
  - pre-increment versus post-increment,
  - single quotes versus double quotes,
  - `echo` versus `print`,
  - manual inlining,
  - ordinary function-call removal,
  - or obscure branchless expressions.
- Choose single-quoted or double-quoted strings according to interpolation, escaping and readability.
- Prefer built-in functions when they clearly express the operation and provide the required semantics.
- Do not replace clear code merely because a native function is presumed faster.
- Avoid clever bit-level or branchless tricks unless the path is demonstrably hot and the benefit is measured.
- Never weaken validation, type safety, error handling or security for a minor speed improvement.
- Document non-obvious performance code with the reason, evidence and constraints that justify it.

## OPcache-Friendly Code Structure

- Write PHP that can be loaded from stable, deterministic files and reused unchanged across requests.
- Treat OPcache compatibility as part of code structure, autoloading, build generation and deployment design.
- Do not distort clear architecture for speculative OPcache micro-optimizations.
- Keep classes, interfaces, traits, enums and functions in predictable Composer-autoloadable locations.
- Prefer one primary autoloadable symbol per file.
- Do not combine unrelated classes into large files merely to reduce file count.
- Do not create unnecessary wrappers or fragmented files that add autoload and declaration overhead without design value.
- Keep autoloadable files free from request-specific top-level execution and unrelated side effects.
- Prefer file-scope declarations with runtime decisions inside methods.
- Avoid conditional class, function, enum, interface or trait declarations unless explicitly required.
- Avoid named functions declared inside methods, conditions or loops.
- Avoid `eval()`, runtime source generation and dynamically generated PHP classes unless technically necessary and measured.
- Do not generate, rewrite or modify application PHP source files during normal request processing.
- Keep deployed PHP source immutable for the lifetime of a release.

## Loading And Autoloading

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

## Generated Runtime Metadata

- Compile stable configuration, routes, dependency mappings, event mappings, serializer metadata and similar discovery-heavy structures during build or deployment.
- Prefer deterministic generated PHP files over reparsing YAML, XML, JSON, `.env`, annotations or directory structures on every request.
- Do not parse `.env` files in production request paths.
- Load production configuration from the process environment or deployment-generated immutable configuration.
- Do not regenerate compiled metadata from a normal application request.
- Version generated metadata with the application release.
- Write generated files atomically before exposing them to workers.
- Validate generated PHP files before activating a release.

## OPcache-Aware Deployment

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

## OPcache Capacity And Warm-Up

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
- Do not call `opcache_compile_file()` from normal request paths.
- Treat preloading as optional and workload-dependent.
- Preload only stable, frequently used code after measurement.
- Require a process restart when preloaded code changes.
- Treat JIT separately from OPcache and enable it only when representative benchmarks show meaningful benefit.

## Realpath And Filesystem Resolution

- Use stable, canonical paths.
- Size PHP’s realpath cache according to actual file and path usage.
- Use longer realpath cache TTLs only with immutable or rarely changing deployments.
- Account for security settings that disable realpath caching.
- Do not weaken required security boundaries merely to enable path caching.
- Avoid unnecessary repeated filesystem checks even when realpath caching is enabled.

## Runtime And Process Management

- Size PHP-FPM workers from measured per-worker memory, CPU demand, expected concurrency and downstream capacity.
- Do not increase worker counts beyond what databases, caches, filesystems and external services can sustain.
- Configure request timeouts and slow-request logging where appropriate.
- Recycle workers deliberately when required to control fragmentation, leaks or long-lived state.
- Keep production debugging, profiling, assertions and development tooling disabled unless actively required.
- Enable OPcache for CLI only when repeated or long-running CLI workloads materially benefit.
- Do not assume newer runtime features are faster without measurement.

## OPcache Observability

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

## Security And Operations

- Apply secure-by-default design and OWASP-aligned practices where relevant.
- Treat validation, escaping, authorization, secrets management and dependency hygiene as mandatory.
- Validate at trust boundaries.
- Encode for the destination context.
- Prefer safe failure, reduced blast radius, idempotency and operationally safe behavior.
- Consider logs, metrics, traces, health checks, timeouts, retries and configuration discipline.
- Follow Twelve-Factor principles where they fit.
- Avoid exposing sensitive diagnostic or implementation details in production errors.
- Ensure performance optimizations cannot bypass authorization, tenant isolation, validation or audit requirements.
- Treat resource exhaustion as both a security and reliability concern.
- Bound request sizes, collection sizes, recursion, concurrency, execution time and retry behavior.

## Tooling And Workflow

- Respect repository tooling and quality checks such as formatting, static analysis, refactoring, tests, mutation testing, profiling and benchmarks.
- Use the project’s existing automation instead of manually enforcing style.
- Use static analysis at the strongest practical level supported by the project.
- Add or update tests for affected behavior, boundaries, failures and contracts.
- Add benchmarks only for meaningful, stable, performance-sensitive behavior.
- After implementation or function/method documentation work, use the automation and workflow described in [AGENTS.md](vendor\infocyph\phpforge\resources\AGENTS.md) if exists.
- Review automated changes for correctness.
- Keep automated changes within scope.
- Do not accept generated or automated refactoring without reviewing the resulting behavior.
- Report which formatting, analysis, tests, profiling and benchmarks were run.
- Clearly state anything that could not be verified.

## Performance Acceptance And Enforcement

- Treat these instructions as engineering guardrails, not proof that an implementation is performant.
- Define measurable performance budgets for each important request, command, worker and batch workflow.
- Do not use one universal latency, memory, query-count or throughput target for unrelated workloads.
- Establish a production-representative baseline before changing a performance-sensitive path.
- Validate performance against representative:
  - data volume,
  - data distribution,
  - concurrency,
  - request mix,
  - dependency latency,
  - cache state,
  - and failure conditions.
- Measure end-to-end behavior in addition to isolated functions.
- Track at minimum where relevant:
  - `p50`, `p95` and `p99` latency,
  - throughput,
  - CPU usage,
  - peak memory,
  - allocation or memory growth,
  - query count,
  - rows examined and returned,
  - external calls,
  - cache hit rate,
  - lock time,
  - timeout rate,
  - and error rate.
- Add automated performance regression checks only where the workload and environment are stable enough to produce a reliable signal.
- Define an acceptable regression tolerance for each benchmark.
- Do not fail builds based on timing differences that fall within normal benchmark variance.
- Use static analysis to enforce structural rules, but do not treat static-analysis success as performance validation.
- Use query-plan analysis for important database paths.
- Use load testing for concurrency-sensitive and capacity-sensitive paths.
- Use soak testing for workers, consumers, daemons and processes where memory growth or resource leakage is possible.
- Compare cold-start and warm-runtime performance separately.
- Test with the same PHP major/minor version, extensions, Composer mode, OPcache configuration and relevant infrastructure used in production.
- Record benchmark and load-test commands so results can be reproduced.
- Verify critical performance metrics after deployment.
- Define rollback or mitigation criteria for material latency, throughput, memory, timeout or error-rate regressions.
- Review performance budgets as traffic, data volume, infrastructure and business requirements change.
- Optimize the dominant measured bottleneck rather than attempting to apply every possible optimization.
- Prefer maximum justified performance over maximum theoretical performance.
- Do not accept a performance gain that materially compromises correctness, security, reliability or operational safety unless the trade-off is explicitly approved.
