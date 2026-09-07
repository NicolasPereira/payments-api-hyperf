# Research: PicPay Simplificado — Transferências

**Feature**: `001-picpay-simplificado-transferencias`
**Date**: 2026-08-30

## Decision 1: Domain Validation for CPF and CNPJ (incl. CNPJ Alfanumérico IN RFB 2.229/2024)

- **Decision**: Model document normalization and CPF/CNPJ validation as a pure
  domain capability. The input is normalized (strip `.-/ ` and spaces, `uppercase` for CNPJ) before format and check-digit
  validation, and the normalized value is the only value passed to persistence.
  CPF remains `^[0-9]{11}$` with traditional módulo 11.
  CNPJ follows IN RFB nº 2.229/2024: `^[A-Z0-9]{12}[0-9]{2}$` (12 alfanuméricos `[A-Z0-9]` + 2 DVs numéricos), with legacy `^[0-9]{14}$` still valid (retrocompatível). DV is computed via `valor = ASCII(c) - 48` (`0-9=0-9`, `A=17, B=18 ... Z=42`), weights `2-9` right-to-left, `módulo 11` (`DV=0` if resto `0`/`1`, else `11-resto`), per Manual RFB. Uniqueness is case-insensitive on normalized form; storage is `VARCHAR(14)` uppercase.
- **Rationale**: FR-020 through FR-025 require official Brazilian validation,
  rejection before persistence, and independence from HTTP, database, and
  infrastructure. IN 2.229/2024 (vigência julho/2026) extends CNPJ to alphanumeric while preserving 14 positions and numeric DVs; supporting it now keeps the MVP compliant with the current Brazilian registration reality and avoids rejecting legitimate new merchants. Keeping the rule in the domain makes it unit-testable and
  reusable by any delivery mechanism.
- **Alternatives considered**:
  - Validate only in the controller: rejected because business rules would be
    coupled to delivery and could be bypassed by another entry point.
  - Validate through a database constraint or remote service: rejected because
    format and check digits are domain rules and must not depend on I/O.
  - Select a concrete third-party library in the spec: deferred to
    implementation because the user explicitly requested the library decision
    in planning/implementation.
  - Keep CNPJ strictly `^[0-9]{14}$`: rejected because it contradicts IN 2.229/2024 and `spec.md` Clarification 2026-09-07; would reject valid new CNPJs like `12ABC34501DE35`.

## Decision 2: Monetary Input and Representation

- **Decision**: The HTTP contract accepts `value` only as a decimal string with
  exactly two fractional digits, for example `"100.00"`. The application
  converts it to an exact monetary value before arithmetic; floating-point
  arithmetic is never used.
- **Rationale**: This preserves the clarification in the spec and avoids JSON
  number precision ambiguity. The persistence design uses an exact decimal
  column with two fractional places, while the domain treats the value as an
  exact amount.
- **Alternatives considered**:
  - JSON number: rejected by clarification Q5 because it can introduce
    floating-point ambiguity.
  - Arbitrary decimal precision: rejected for this MVP because the contract
    and BRL assumption define two fractional digits.

## Decision 3: Transfer Idempotency

- **Decision**: Implement the specified internal fingerprint from normalized
  `payer + payee + value`, stored in Redis with a three-minute TTL and an
  atomic create-if-absent operation. The stored value references the transfer
  result so a repeated request in the window returns the same outcome.
- **Rationale**: This is the explicit clarification selected by the product
  owner and uses the project Redis constraint. An atomic Redis operation avoids
  two concurrent requests both entering the financial mutation path.
- **Risk and mitigation**: The fingerprint cannot distinguish two intentional
  equal transfers made by the same payer to the same payee within three
  minutes, and expiry permits a later retry to execute again. The plan MUST
  expose this as a documented MVP limitation, emit telemetry for idempotency
  hits/misses, and keep the transfer record/audit trail in MySQL. A future
  revision SHOULD use a client idempotency key with durable retention; that
  change requires a spec clarification and compatibility plan.
- **Alternatives considered**:
  - Client-provided `Idempotency-Key`: technically safer, but rejected for this
    iteration by clarification Q3.
  - Hash plus a timestamp/request ID: rejected because retries could not derive
    the same key.
  - Redis only without a transfer record: rejected because financial state and
    auditability belong in durable relational storage.

## Decision 4: Financial Transaction and Concurrency

- **Decision**: Authorize before the financial mutation, then execute transfer
  creation, payer debit, payee credit, and Outbox insertion in one relational
  transaction. Lock both wallets in deterministic user-ID order before
  checking and changing balances.
- **Rationale**: This prevents negative balances and deadlocks under concurrent
  transfers while ensuring that an incomplete debit/credit pair rolls back.
  The authorization call is outside the database transaction to avoid holding
  locks during external I/O.
- **Alternatives considered**:
  - Debit first and compensate after authorization: rejected because it creates
    an avoidable inconsistent state.
  - Hold database locks while calling the authorizer: rejected because an
    external timeout would unnecessarily block other transfers.
  - Application-only locking: rejected because it does not protect against
    multiple worker processes or replicas.

## Decision 5: Notification Delivery

- **Decision**: Use a durable transactional Outbox. The transfer and its
  pending notification event are committed together. A dedicated asynchronous
  worker claims pending events, calls the notification endpoint, retries up to
  three times, and records `sent` or `failed` without reversing the transfer.
- **Rationale**: The Outbox guarantees that a committed transfer has a durable
  notification intent without coupling financial success to third-party
  availability. It also supplies retry and observability state.
- **Alternatives considered**:
  - Synchronous notification before returning: rejected because the dependency
    is explicitly unstable and must not block or reverse financial success.
  - In-memory event dispatch: rejected because process failure could lose the
    notification.
  - A new message broker: rejected for MVP; the project already has MySQL and
    introducing a broker requires an ADR under the Constitution.

## Decision 6: External Service Contracts

- **Decision**: Use an application-owned authorizer port for `GET
  https://util.devi.tools/api/v2/authorize` and an application-owned notifier
  port for `POST https://util.devi.tools/api/v1/notify`. The adapter maps the
  observed authorizer response `{ "status": "success", "data": {
  "authorization": true } }` to an authorized result; any non-success,
  malformed, timeout, or 5xx response is not treated as authorization.
  Notification success accepts the observed `204` response; non-2xx is a
  retryable or final failure according to the retry policy.
- **Rationale**: Ports keep the domain/application independent of HTTP and
  isolate payload mapping. The endpoints are external dependencies, so their
  behavior must be covered by contract tests and failure scenarios.
- **Operational note**: A local diagnostic request encountered an expired TLS
  certificate in the current environment. Production and application clients
  MUST keep certificate verification enabled; disabling TLS verification is
  not an implementation option.
- **Alternatives considered**:
  - Calling the URLs from the use case: rejected because it violates dependency
    inversion and makes unit tests dependent on network I/O.
  - Treating any 2xx authorizer response as approval: rejected because malformed
    or unexpected payloads must not authorize money movement.

## Decision 7: API Surface

- **Decision**: Define `POST /users` as the canonical registration endpoint
  implied by the spec's independent test, and `POST /transfer` as the required
  transfer endpoint. Both return a consistent JSON error envelope and use
  REST resource semantics.
- **Rationale**: The original transfer contract is explicit while registration
  says “POST /users (or equivalent)”. Choosing `/users` makes the plan and
  acceptance tests executable without adding another naming decision.
- **Alternatives considered**:
  - Leave registration endpoint unspecified: rejected because contract tests
    and task decomposition need a stable public boundary.
  - Add multiple registration aliases: rejected as unnecessary compatibility
    surface and contrary to YAGNI.

## Decision 8: Background Worker Mechanism

- **Decision**: Use a Hyperf-compatible long-running process that polls and
  claims Outbox records. Keep the worker behind an application service so the
  process mechanism can be replaced later without changing the domain.
- **Rationale**: The repository has no queue broker dependency and the
  Constitution requires justification for major infrastructure additions. A
  database-backed worker satisfies the MVP reliability requirement with the
  existing stack.
- **Alternatives considered**:
  - Add RabbitMQ/Kafka: rejected as disproportionate infrastructure for the
    current scope and requires an ADR.
  - Run notification inline: rejected by the Outbox decision.

## Decision 9: Contract Consistency After Clarification

- **Decision**: Treat clarification Q5 as authoritative for the transfer
  payload: `value` is a string with exactly two fractional digits. Update the
  stale `FR-005` number wording and `SC-011` client-key wording so the spec,
  contracts and plan describe the same behavior.
- **Rationale**: The original challenge example used a JSON number, but the
  later approved clarification explicitly selected an exact decimal string and
  an internal fingerprint. Leaving either older phrase would create conflicting
  acceptance criteria and implementation tasks.
- **Alternatives considered**:
  - Keep the old wording: rejected because it contradicts the accepted
    clarification and the financial exactness principle.
  - Support both number and string: rejected by clarification Q5.

## Decision 10: Password Hashing (A1 — Ambiguity FR-001 "hash seguro")

- **Decision**: Use PHP `password_hash()` with `PASSWORD_ARGON2ID` as primary (PHP 8.4, `sodium` ext). Fallback to `PASSWORD_BCRYPT` (`cost 12`) if `argon2id` unavailable in runtime. `password_verify()` + `password_needs_rehash()` on login. Store only `password_hash` (`VARCHAR(255)`), never plain.
- **Rationale**: `Constitution V:119` exige `strict_types` + `Security by default`. `ARGON2ID` (memory-hard, `memory_cost 65536`, `time_cost 4`, `threads 1`) resiste a GPU/ASIC vs `BCRYPT` e é nativo em PHP 8.4; `BCRYPT cost 12` mantém compatibilidade se `sodium` não estiver em container mínimo. Ambos são `one-way` + `salt` automático, validáveis via `password_verify` sem expor PII em logs/OTEL.
- **Alternatives considered**:
  - `PASSWORD_BCRYPT` only: rejeitado como primário porque `ARGON2ID` oferece melhor resistência com custo similar em PHP 8.4; mantido como fallback.
  - `PASSWORD_ARGON2I`: rejeitado — `ARGON2ID` combina resistência `I`+`D`, é recomendado OWASP 2024.
  - Hash custom/sha256: rejeitado — viola `V:126` (secrets) e não é `adaptive`.

## Unresolved Decisions for Implementation

No blocking `NEEDS CLARIFICATION` items remain. The following are deliberately
implementation-level decisions for `/speckit.tasks` and `/speckit.implement`:

- The concrete CPF/CNPJ validation library, if any.
- Exact application-owned port and adapter class names.
- Worker polling interval already decided as `backoff 10s/60s/300s` + `lease 30s` per `plan.md:149` (Decision remediated).
- Exact authentication strategy, which remains out of scope for this MVP.
- Exact error message language, while the error codes remain stable.
- The concrete OpenTelemetry PHP SDK/instrumentation package and exporter;
  telemetry remains mandatory, but package selection belongs in tasks.
