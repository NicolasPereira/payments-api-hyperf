<!-- Sync Impact Report
Version change: 2.1.0 → 2.2.0
Modified principles:
- None (all 7 principles I-VII preserved verbatim)
Added sections:
- PHP-FIG Standards — MUST follow applicable PHP-FIG standards; MUST PSR-1 (basic coding) and PSR-4 (autoloading/namespaces); MUST PER Coding Style (PER-CS) as successor to PSR-12 for PHP 8.4; project conventions MUST NOT contradict PHP-FIG without explicit justification/approval; MUST PHP-CS-Fixer (style) and MUST PHPStan (static analysis/quality); only applicable PSRs required
Removed sections:
- None
Follow-up TODOs: none
-->

# payments-api-hyperf Constitution

## Core Principles

### I. Coroutine-First & Hyperf Idioms

The application MUST follow Hyperf 3.2 coroutine-oriented runtime practices.

Code executing within the coroutine runtime MUST avoid blocking operations that can block the event loop. I/O operations MUST use Hyperf-compatible coroutine-aware clients or mechanisms.

Controllers and other delivery mechanisms MUST remain thin and MUST be limited to boundary concerns such as request validation, authorization, serialization, and delegation.

Business logic MUST NOT reside in controllers, consumers, commands, or other delivery mechanisms.

Direct superglobal access and unmanaged blocking I/O in application code are FORBIDDEN.

The runtime MUST remain compatible with the project's supported PHP, Hyperf, and Swoole versions.

---

### II. Test-First Development

Tests MUST be designed before production implementation.

TDD is NON-NEGOTIABLE for business logic and critical application behavior. The preferred development cycle is Red → Green → Refactor.

Tests MUST initially fail for newly introduced behavior and MUST pass after implementation.

Business logic MUST have unit-level tests.

Externally observable application behavior MUST have integration or contract tests where applicable.

External integrations MUST have appropriate contract or integration coverage.

No production code may be merged with failing or intentionally missing tests without a formally documented exception.

New domain code SHOULD maintain a high level of meaningful automated coverage. Coverage thresholds are quality gates and MUST NOT be treated as a substitute for meaningful test behavior.

---

### III. Domain Integrity & Financial Correctness

Financial correctness has priority over implementation convenience.

Monetary values MUST be represented using integer minor units or an exact decimal representation such as `DECIMAL(15,2)`, always with an explicit currency.

Floating-point arithmetic for monetary calculations is FORBIDDEN.

Financial invariants MUST be explicitly modeled and tested.

The system MUST prevent, where applicable:

* double spending;
* negative balances;
* unauthorized balance mutations;
* invalid state transitions;
* duplicate financial side effects;
* inconsistent financial state.

Every state-changing financial operation MUST be idempotent.

Repeated requests using the same idempotency key MUST produce the same business outcome and MUST NOT cause duplicate side effects.

Financial state changes MUST be performed transactionally. Concurrency-sensitive operations MUST use appropriate consistency and locking mechanisms.

Financial operations MUST NOT fail silently.

The system MUST distinguish between successful, failed, pending, and indeterminate financial outcomes whenever the business domain requires such states.

Every financial state mutation MUST be traceable to the operation, actor or responsible system, correlation/trace context, resulting state, and relevant timestamp.

Database migrations affecting financial data MUST preserve data integrity and MUST have a safe rollback or recovery strategy.

---

### IV. Domain-Centric & Hexagonal Architecture

The application MUST follow Domain-Driven Design and Ports-and-Adapters principles where they provide meaningful value to the business domain.

Business rules MUST remain independent from delivery mechanisms, persistence technologies, frameworks, and external service implementations.

Application workflows MUST be represented through explicit Use Cases.

Use Cases MUST orchestrate application behavior without becoming coupled to infrastructure concerns.

The Domain MUST express meaningful business concepts using appropriate DDD patterns, including Entities, Value Objects, Domain Services, Aggregates, and Domain Events when justified by actual domain complexity.

The system MUST follow Dependency Inversion.

High-level business policies MUST NOT depend directly on infrastructure implementations.

External systems MUST be accessed through explicit ports or abstractions at appropriate architectural boundaries.

Infrastructure adapters MUST implement application-owned abstractions rather than forcing domain logic to depend on infrastructure details.

Architectural patterns MUST serve business complexity and MUST NOT be introduced solely for structural consistency or pattern completeness.

YAGNI applies. Simpler solutions SHOULD be preferred when they satisfy the same architectural and business requirements.

---

### V. Security & Data Protection

Security MUST be enforced by default and MUST NOT depend on individual feature authors remembering optional safeguards.

Input MUST be validated at application boundaries.

PHP code MUST use strict typing where applicable through `declare(strict_types=1)`.

Authentication and authorization MUST be explicitly enforced for protected operations.

Payment creation and other abuse-prone financial operations MUST have appropriate rate limiting and replay protection.

Secrets MUST never be hardcoded or committed to the repository. Secrets MUST be provided through the application's configuration and secret-management mechanisms.

Sensitive information MUST NOT be logged.

PII MUST be minimized in logs and telemetry.

Cardholder data MUST NOT be stored as raw PAN. If cardholder data is handled, appropriate PCI-DSS controls and tokenization MUST be applied.

Database queries MUST use parameter binding or safe ORM mechanisms.

Mass assignment MUST be explicitly controlled.

Dependencies MUST be regularly audited for known vulnerabilities.

Security controls MUST be considered during specification, planning, implementation, and review rather than added only after implementation.

---

### VI. Observability & Operational Excellence

All production-critical flows MUST be observable.

The application MUST provide structured logs, metrics, and distributed traces for relevant business and infrastructure operations.

OpenTelemetry MUST be the standard telemetry framework for application traces and metrics.

Externally initiated requests and asynchronous operations MUST preserve correlation and trace context whenever supported by the transport.

Critical payment flows MUST expose sufficient telemetry to diagnose:

* latency;
* throughput;
* failures;
* retries;
* state transitions;
* external dependency failures;
* database behavior;
* distributed execution.

Business-critical operations SHOULD expose business-level metrics.

Infrastructure components MUST expose relevant technical metrics.

Logs MUST be structured and correlated with the active trace context where applicable.

Production logs SHOULD use a machine-readable structured format.

Telemetry MUST NOT contain secrets, payment credentials, unnecessary PII, or sensitive financial data.

Health and readiness mechanisms MUST expose the operational state of critical dependencies where appropriate.

Performance requirements that are specific to a feature MUST be defined in that feature's specification rather than imposed globally without a measurable workload definition.

---

### VII. API & Contract Stability

Public API contracts MUST evolve backward-compatibly by default.

Breaking changes MUST be explicitly identified, versioned, documented, and accompanied by a migration strategy.

API contracts MUST define validation, error behavior, authentication/authorization requirements, and observable business outcomes where relevant.

Clients MUST NOT be forced to depend on internal implementation details.

Changes to externally consumed contracts MUST be treated as potentially breaking until compatibility has been demonstrated.

---

## Technology Stack & Constraints

The project's core runtime is LOCKED to:

* PHP 8.4;
* Hyperf 3.2;
* Swoole 6.2.2;
* MySQL 8.4;
* Redis 8;
* Docker-based development and execution.

Hyperf-native and coroutine-compatible mechanisms MUST be preferred when implementing application functionality.

MySQL is the primary relational persistence mechanism.

Redis is the primary shared in-memory infrastructure mechanism for use cases such as caching, distributed coordination, idempotency, and rate limiting when appropriate.

Additional infrastructure such as message brokers, queues, external PSPs, or other major infrastructure components MUST receive explicit architectural justification and, when the decision has lasting architectural impact, an ADR.

Infrastructure implementation details such as container ports, image tags, Docker networking, and CI-specific configuration SHOULD remain documented in their respective infrastructure or deployment files rather than duplicated here.

Runtime artifacts and generated dependencies MUST NOT be committed to the repository.

---

## PHP-FIG Standards

PHP code MUST follow applicable PHP-FIG standards.

The project MUST follow PSR-1 for basic coding conventions.

The project MUST follow PSR-4 for autoloading and namespace organization.

For coding style, the project MUST follow the PHP-FIG standard currently applicable to PHP 8.4, using PER Coding Style (PER-CS) as the evolution/successor to PSR-12.

Project-specific conventions MUST NOT contradict PHP-FIG standards without explicit technical justification and approval.

PHP-CS-Fixer MUST be used for automated enforcement of coding style.

PHPStan MUST be used for automated enforcement of quality and static analysis.

Only PSRs and standards that are actually applicable to this project are required. An exhaustive list of all existing PSRs MUST NOT be treated as mandatory.

---

## Development Workflow & Quality Gates

Development MUST follow a Spec-Driven Development workflow using Spec Kit.

The standard feature workflow is:

```text
/speckit.specify
        ↓
/speckit.clarify (when necessary)
        ↓
/speckit.plan
        ↓
/speckit.checklist (when useful)
/speckit.analyze (before implementation when applicable)
        ↓
/speckit.tasks
        ↓
/speckit.implement
        ↓
/speckit.converge
```

The Constitution is the architectural and engineering authority for all Spec Kit workflows.

Specifications MUST describe what the system should do and why.

Plans MUST describe how a specific feature will be implemented.

Tasks MUST describe the executable work required to implement the plan.

Architectural decisions with significant long-term consequences SHOULD be recorded as ADRs.

Implementation details MUST NOT be promoted to constitutional principles unless they represent a durable project-wide constraint.

Quality gates for merge MUST include, where applicable:

* automated tests passing;
* static analysis passing;
* code style validation passing;
* constitution compliance;
* relevant security checks;
* relevant architectural checks;
* reviewer approval.

Feature branches SHOULD be created from `main`.

Commits SHOULD follow Conventional Commits.

Pull Requests are REQUIRED for changes to the main branch.

---

## Pull Request Standards

All changes to `main` MUST be submitted through a Pull Request.

Every Pull Request MUST:

* Clearly describe the problem being solved and the expected outcome.
* Reference the corresponding Spec Kit specification when the change is related to a Spec-Driven feature.
* Identify relevant architectural decisions or ADRs.
* Describe important implementation or design decisions.
* Document testing and validation performed.
* Identify security, data, performance, and observability impacts when applicable.
* Identify database schema or migration changes when applicable.
* Explicitly disclose known limitations, risks, or follow-up work.
* Pass all mandatory CI quality gates.
* Receive at least one reviewer approval before merge.

Pull Requests MUST NOT contain unrelated changes.

The Pull Request description MUST provide enough context for a reviewer or AI agent to understand the relationship between the problem, specification, implementation, and validation.

If a requirement can be mechanically enforced, it SHOULD be enforced by CI rather than relying solely on human review.

---

## Commit Standards

All commits MUST follow the Conventional Commits specification.

Commit messages MUST use the following structure:

```text
<type>(<scope>): <description>

[optional body]

[optional footer]
```

Allowed commit types MUST include, at minimum:

* `feat` — new functionality
* `fix` — bug fix
* `refactor` — code restructuring without behavior change
* `test` — tests only
* `docs` — documentation only
* `chore` — maintenance tasks
* `build` — build system or dependency changes
* `ci` — CI/CD changes
* `perf` — performance improvements
* `revert` — revert a previous commit

Commit descriptions MUST be concise, imperative, and written in English.

Breaking changes MUST be explicitly identified using the Conventional Commits `BREAKING CHANGE` footer or the `!` notation.

Each commit MUST represent one coherent logical change.

Files belonging to different logical changes MUST be committed separately.

A commit MAY contain multiple files when those files are required to implement the same logical change.

The agent MUST NOT use broad staging operations such as:

```text
git add .
git add -A
```

when they could include unrelated changes.

Before creating a commit, the working tree and staged diff MUST be reviewed to ensure that every staged file belongs to the intended logical change.

Generated files, runtime artifacts, dependencies, secrets, and unrelated modifications MUST NOT be included in a commit.

Commits SHOULD remain small, focused, and independently understandable.

AI-generated commits MUST follow the same standards as human-created commits and MUST NOT combine unrelated changes merely for convenience.

When practical, commits SHOULD maintain traceability to the corresponding Spec Kit task or logical unit of work.

For example:

* `T001 → feat(payment): add payment entity`
* `T002 → feat(payment): implement payment creation`
* `T003 → test(payment): cover payment creation flow`

---

## Quality Gates

Quality gates for merge MUST include, where applicable:

* automated tests passing;
* static analysis passing;
* code style validation passing;
* constitution compliance;
* relevant security checks;
* relevant architectural checks;
* relevant integration and contract tests;
* Pull Request requirements satisfied;
* Conventional Commit requirements satisfied.

Feature branches SHOULD be created from `main`.

Pull Requests are REQUIRED for changes to the main branch.

Direct commits to `main` are FORBIDDEN unless explicitly authorized for emergency recovery or repository administration.

If a quality gate cannot be satisfied, the exception process defined in this Constitution MUST be followed.

---

## Exceptions

A constitutional principle MAY be violated only through an explicit exception.

An exception MUST:

1. Identify the violated principle.
2. Explain the technical or business reason.
3. Define the affected scope.
4. Document the associated risks and trade-offs.
5. Define a remediation plan when the violation is temporary.
6. Receive approval from the project owner.

Exceptions MUST NOT become a permanent mechanism for bypassing architectural governance.

Repeated exceptions to the same principle SHOULD trigger a constitutional review.

---

## Governance

This Constitution supersedes conflicting project-level engineering practices unless an explicit constitutional exception has been approved.

The normative terms have the following meaning:

* **MUST / REQUIRED** — mandatory. Violation requires an approved exception.
* **SHOULD** — strong recommendation. Deviation requires technical justification.
* **MAY** — optional and permitted.

Amendments to this Constitution REQUIRE:

1. A documented rationale in the Pull Request.
2. A semantic version bump.
3. Approval by the project owner.
4. An updated Sync Impact Report.
5. Review of affected specifications and architectural decisions when the amendment changes existing constraints.

Versioning follows:

* **MAJOR** — removal, replacement, or incompatible redefinition of a principle.
* **MINOR** — addition of a new principle or significant new governance requirement.
* **PATCH** — clarification, wording improvement, or non-semantic correction.

When a constitutional amendment invalidates existing project assumptions, affected specifications MUST be reviewed and a migration plan SHOULD be created.

The authoritative copy of this Constitution is:

```text
.specify/memory/constitution.md
```

Spec Kit templates, plans, tasks, and implementation guidance MUST defer to this document when conflicts occur.

**Version**: 2.2.0 | **Ratified**: 2026-08-30 | **Last Amended**: 2026-08-30
