# Implementation Plan: PicPay Simplificado - Transferencias

**Branch**: `001-picpay-simplificado-transferencias` | **Date**: 2026-08-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/001-picpay-simplificado-transferencias/spec.md`

## Summary

Implement a RESTful MVP for user registration and money transfers. Common users
can transfer to common users or merchants; merchants can only receive. The
implementation will keep document validation and money rules in the domain,
orchestrate behavior through explicit Use Cases, use MySQL transactions for
the financial mutation, Redis for the specified three-minute idempotency window,
and a durable MySQL Outbox for asynchronous notification delivery.

The implementation must satisfy the public contracts in
[`contracts/users.yaml`](contracts/users.yaml) and
[`contracts/transfer.yaml`](contracts/transfer.yaml), while keeping external
HTTP calls behind application-owned ports.

## Technical Context

**Language/Version**: PHP 8.4, strict typing

**Primary Dependencies**: Hyperf 3.2, hyperf/database, hyperf/redis,
hyperf/guzzle, Swoole 6.2.2, OpenTelemetry PHP SDK/instrumentation,
PHP-CS-Fixer, PHPStan

**Storage**: MySQL 8.4 for users, wallets, transfers and Outbox; Redis 8 for
the internal idempotency fingerprint and result within the three-minute window

**Testing**: co-phpunit via `composer test`; unit tests for domain/application;
HTTP integration tests; external authorizer/notifier contract tests; PHPStan;
PHP-CS-Fixer

**Target Platform**: Linux container, Docker Compose, Hyperf HTTP server

**Project Type**: RESTful web service

**Performance Goals**: Registration under 5 seconds; successful transfer under
3 seconds at p95; validation scenario exercises 100 transfers/minute as stated
by SC-001, SC-002 and SC-013

**Constraints**: No floating-point money arithmetic; `value` is a decimal
string with exactly two fractional digits; invalid documents are rejected before
persistence; merchant cannot pay; authorization precedes financial mutation;
transfer and Outbox row commit atomically; notification failure never reverses
a completed transfer; no deposit endpoint in this feature

**Scale/Scope**: MVP with low expected load; two user types, one wallet per user,
registration, `POST /transfer`, authorizer integration, notification Outbox and
worker. Deposit, authentication, frontend and a new message broker are out of
scope.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Gate | Status | Evidence / action |
|------|--------|------------------|
| Hyperf coroutine runtime and non-blocking I/O | PASS | External calls use Hyperf-compatible HTTP client behind ports; worker is asynchronous |
| Thin delivery mechanisms and business logic outside controllers | PASS | Controllers validate, serialize and delegate to Use Cases |
| Test-first and business/integration/contract coverage | PASS | Tests are planned before implementation for each Use Case and boundary |
| Financial exactness and invariants | PASS | Exact decimal value, non-negative balance, deterministic wallet locks, atomic transfer |
| Idempotency | CONDITIONAL | Spec-mandated hash plus Redis TTL 3m is atomic but has false-positive/expiry risk; documented in research and quickstart as MVP limitation |
| DDD, Ports & Adapters, Dependency Inversion | PASS | Domain rules have no framework/DB/HTTP dependency; external services use ports |
| Security and data protection | PASS | Password hash only, input validation, no sensitive data in logs, parameterized queries |
| Observability and OpenTelemetry | PASS | Correlation IDs, structured logs, transfer/outbox states and telemetry hooks planned |
| API contract stability | PASS | OpenAPI contracts define request, response and error behavior |
| PHP-FIG / PSR-1 / PSR-4 / PER-CS | PASS | Strict typing, Composer PSR-4 namespaces and PHP-CS-Fixer enforcement |
| Additional infrastructure / ADR | PASS | No broker added; MySQL-backed Outbox worker uses existing stack |

The conditional idempotency gate is accepted only for this MVP because it is an
explicit clarification in the approved spec. The implementation MUST make the
limitation visible in documentation and telemetry; changing retention or key
semantics requires a new specification clarification.

## Project Structure

### Documentation (this feature)

```text
specs/001-picpay-simplificado-transferencias/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command)
├── data-model.md        # Phase 1 output (/speckit.plan command)
├── quickstart.md        # Phase 1 output (/speckit.plan command)
├── contracts/           # Phase 1 output (/speckit.plan command)
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)

```text
app/
├── Domain/
│   ├── User/                  # User type, document, email and password rules
│   ├── Wallet/                # Balance invariants and money value
│   └── Transfer/              # Transfer entity, states and financial rules
├── Application/
│   ├── User/                  # CreateUser use case
│   ├── Transfer/              # ExecuteTransfer use case
│   └── Notification/          # ProcessNotificationOutbox use case
├── Infrastructure/
│   ├── Persistence/            # MySQL models, migrations and repositories
│   ├── Cache/                  # Redis idempotency adapter
│   ├── External/               # Authorizer and notifier HTTP adapters
│   └── Notification/           # Outbox worker/process adapter
├── Controller/
│   ├── UserController.php
│   └── TransferController.php
└── Exception/

config/
├── routes.php                  # /users and /transfer routes
└── autoload/processes.php      # Outbox worker registration

test/
├── Unit/                       # Domain and application tests
├── Integration/                # MySQL/Redis and transaction tests
├── Contract/                   # Authorizer/notifier and HTTP API contracts
└── Cases/                      # Existing Hyperf HTTP test base/cases
```

**Structure Decision**: Single Hyperf REST service with Domain, Application,
Infrastructure and Delivery boundaries under `app/`. Domain code owns business
rules and ports; infrastructure implements persistence, Redis, HTTP and worker
adapters. Tests mirror the boundary types under `test/`. No frontend or second
service is introduced.

## Implementation Flow

1. Validate and normalize registration input at the delivery boundary, then
   construct domain values. CPF/CNPJ validation remains pure and runs before
   opening persistence work.
2. Create User and Wallet atomically, protected by normalized document and email
   uniqueness constraints. The password is hashed before storage and never
   returned.
3. Validate transfer payload and derive the internal idempotency fingerprint.
   Atomically reserve or read the Redis key before entering the financial path.
4. Load payer and payee, reject missing users, self-transfer and merchant payer;
   check the payer balance before calling the authorizer.
5. Call the authorizer through its port. Only the expected authorization result
   allows the financial transaction to proceed.
6. In one MySQL transaction, lock wallets in ascending user-ID order, recheck
   balance, create the transfer, debit payer, credit payee and insert the
   pending notification Outbox row.
7. Return the completed transfer response after commit. A worker claims pending
   Outbox rows, calls the notifier through its port, retries up to three times,
   and records delivery status without changing financial state.

## Testing Strategy

- Domain unit tests: CPF/CNPJ normalization and validity, email normalization,
  password length, user type/document compatibility, money parsing, transfer
  invariants and state transitions.
- Application unit tests: CreateUser, ExecuteTransfer and Outbox processing with
  ports mocked; verify authorization ordering and failure behavior.
- Integration tests: unique constraints, User/Wallet atomic creation, wallet
  locking and rollback, Redis atomic idempotency reservation, and Outbox insert
  in the same transaction as transfer.
- Contract tests: `POST /users`, `POST /transfer`, authorizer success, denial,
  malformed, timeout and 5xx, notifier 204/4xx/5xx/timeout, and consistent
  errors.
- Quality commands: `composer test`, `composer analyse`, and PHP-CS-Fixer dry
  run. New production behavior is implemented only after its tests fail.

## Failure and Recovery Strategy

- Authorizer failure prevents the financial transaction and leaves balances
  unchanged.
- Any financial transaction exception rolls back transfer, both wallet changes,
  and the Outbox row.
- Notification failure leaves the transfer completed and retries the Outbox up
  to three times; exhausted rows remain observable as `failed`.
- A worker lease expiry returns a stuck Outbox row to `pending`.
- Redis loss can remove the three-minute idempotency window; the durable MySQL
  transfer/audit record remains authoritative for financial history. This is a
  known MVP limitation and must be monitored.

## Complexity Tracking

No constitutional violations are planned. The Outbox worker and application
ports are justified by explicit financial reliability and architecture rules,
not by pattern completeness. The internal hash idempotency limitation is an
approved spec tradeoff and is tracked under the Constitution Check and
`research.md`, not hidden as complexity.

## Post-Design Constitution Check

All Phase 1 artifacts are consistent with Constitution v2.2.0:

- No new infrastructure component or broker is introduced.
- Domain validation is independent of HTTP, database and framework code.
- Financial mutation and Outbox insertion share one transaction boundary.
- External integrations are ports/adapters and retain TLS verification.
- API contracts define observable outcomes and stable error categories.
- Test, static-analysis, coding-style, security, architecture, OpenTelemetry
  and contract checks are included in the implementation plan.
