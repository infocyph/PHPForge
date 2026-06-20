# PHPForge Engineering Instructions

## Core Principles

- Stay framework-agnostic unless the project clearly requires otherwise.
- Prioritize: correctness, security, performance, reliability, maintainability.
- Prefer simple, explicit, measurable solutions.
- Avoid speculative architecture, unnecessary abstractions, and unnecessary layers.
- Follow project conventions unless they conflict with correctness, security, or a clearly better design.
- Use modern PHP, relevant PHP-FIG standards, strict typing, and clear contracts where appropriate (`PER`, `PSRs`).
- Use `SOLID` pragmatically; prefer composition over inheritance unless inheritance is the better fit.
- Choose the option with the best balance of correctness, security, speed, operational safety, and maintainability.
- Be skeptical of defaults and unnecessary complexity; prefer explicit trade-offs over hidden cost.
- When in doubt, choose the simpler, easier-to-measure design.

## Code Design

- Keep methods, functions, and closures small and clear; split them when complexity gets high.
- Avoid deep nesting, hidden side effects, and mixed responsibilities.
- Add appropriate docblocks where useful.
- Focus on the active task and the smallest correct change set.
- Do not do broad cleanup, refactoring, renaming, file moves, or rewrites without explicit approval.
- Do not change behavior outside scope unless required for correctness, security, or stability.

## Performance And Runtime

- Optimize for low overhead, low latency, and predictable runtime behavior.
- Treat hot paths, `I/O`, query cost, allocations, and serialization as first-class concerns.
- Avoid unnecessary work in critical paths; prefer efficient patterns such as batching, streaming, and chunking where relevant.
- Prefer tooling, profiling, benchmarking, and tests over assumptions.
- Do not claim performance improvements without measurement or strong technical reasoning.

## Security And Operations

- Apply secure-by-default thinking and follow `OWASP`-aligned practices where relevant.
- Treat validation, escaping, authorization, secrets, and dependency hygiene as mandatory.
- Prefer safe failure, reduced blast radius, idempotency, observability, and operationally safe behavior.
- Keep solutions production-aware; consider logs, metrics, traces, health checks, and config discipline when relevant.
- Follow Twelve-Factor principles where they fit.

## Tooling And Workflow

- Respect repository tooling and quality checks such as formatting, refactoring, static analysis, and tests.
- When relevant, use the project's existing automation instead of enforcing style manually.
- After implementation or function/method documentation work, use the automation and workflow described in [AGENTS.md](./AGENTS.md).
- Review automated changes for correctness and keep scope controlled.
