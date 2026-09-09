# Specification Quality Checklist: PicPay Simplificado — Transferências

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-30
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs) — external mocks described as dependencies, validação de documento como regra de domínio sem biblioteca/classe/ORM
- [x] Focused on user value and business needs — cadastro com CPF/CNPJ, carteira, transferência, autorizador, notificação
- [x] Written for non-technical stakeholders — plain language, regras de negócio explícitas
- [x] All mandatory sections completed — User Scenarios, Requirements, Success Criteria present

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain — validação CPF/CNPJ agora explícita; sem ambiguidade crítica
- [x] Requirements are testable and unambiguous — FR-020 a FR-025 com MUST, normalização, rejeição 422 antes de persistência, domínio puro
- [x] Success criteria are measurable — SC-001 a SC-013 com métricas (tempo, %, 100% rejeição de inválidos)
- [x] Success criteria are technology-agnostic — sem Hyperf/MySQL/Redis, apenas resultados observáveis
- [x] All acceptance scenarios are defined — 4 user stories com Given/When/Then, incluindo 9 cenários para User Story 3 (CPF/CNPJ)
- [x] Edge cases are identified — 13 casos incluindo formato inválido, DV inválido, todos iguais, formatação, incompatibilidade tipo-documento, normalização
- [x] Scope is clearly bounded — transferência `POST /transfer` + cadastro com validação de documento; depósito explicitamente fora do escopo
- [x] Dependencies and assumptions identified — autorizador `GET /authorize`, notify `POST /notify`, idempotência, validação domínio

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria — FR-020→FR-025 mapeados para cenários de User Story 3
- [x] User scenarios cover primary flows — P1 common→common, P2 common→merchant, P3 cadastro com CPF/CNPJ validado, P4 resiliência externa
- [x] Feature meets measurable outcomes defined in Success Criteria — SC-002/SC-003/SC-004 cobrem validação de documento
- [x] No implementation details leak into specification — sem biblioteca, classe, ORM ou algoritmo específico

## Implementation Status (2026-09-09 — Phase 7 Polish T058-T065)

- [x] T006-T015 Foundational (migrations, Money, Domain Exceptions, ExceptionMapper, Database locks, RedisIdempotency, Ports, routes, OTEL, Authorizer/Notifier adapters)
- [x] T016-T031 US3 Cadastro (CPF/CNPJ alfa IN 2.229/2024, Email, User/Wallet, CreateUserUseCase, UserController, atomicidade)
- [x] T032-T043 US1 Transfer common→common (Money/Transfer invariants, TransferValue, ExecuteTransferUseCase com Authorizer, Transaction + Outbox, TransferController, Idempotency 3min)
- [x] T044-T048 US2 common→merchant (merchant payer 403 merchant_payer_blocked + metrics, payee merchant permitido)
- [x] T049-T057 US4 Resiliência (authorizer 403/502/503 sem mutação, notifier Outbox pending→sent/failed ≤3 retries, worker lease, OTEL correlation)
- [x] T058 Quickstart validação completa Docker http://localhost:9501 (scripts/quickstart-validate.sh cobre CPF/CNPJ alfa, transferências, idempotency, resiliência)
- [x] T059 Seed saldos database/seeders/TestBalanceSeeder.php (sem /deposit per Clarification 2026-08-30)
- [x] T060 EdgeCasesTest unit adicionais (value zero/negativo/number, payer==payee, documento lowercase com espaços, email case-insensitive)
- [x] T061 Performance smoke 100 transfers/min SC-013 (test/Integration/PerformanceTest.php — p95 <3s, erro <1%, throughput validado)
- [x] T062 Security hardening app/Controller/ (rate limiting 60/100 req/min via Redis, replay protection correlation_id 60s, boundaries, no PII/secrets em logs sanitizados)
- [x] T063 Quality gates (composer test, composer analyse, php-cs-fixer dry-run — violações corrigidas)
- [x] T064 README + requirements atualizados
- [x] T065 Idempotência hash 3min documentada em quickstart.md + telemetry hits/misses per research Decision 3

**SC coverage**: SC-001..SC-013 validados via tests/contract/integration + quickstart script. SC-005 p95 <3s, SC-013 100/min <1% erro via PerformanceTest. Idempotência MVP limitação documentada e monitorada.

## Notes

- Revisão incorporada em 2026-08-30: adicionadas regras CPF válido para common, CNPJ válido para merchant, validação oficial brasileira (formato, normalização, dígitos verificadores), normalização antes de validação/persistência, rejeição 422 antes de persistência, validação como regra de domínio sem infra/DB/HTTP, unicidade mantida sobre forma normalizada.
- All items pass. Spec ready for `/speckit.plan`.
- Polish 2026-09-09: Feature completa, pronta para PR; T058-T065 marcados [x] em tasks.md (não commitado per instruções).
