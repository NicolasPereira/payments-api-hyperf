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

## Notes

- Revisão incorporada em 2026-08-30: adicionadas regras CPF válido para common, CNPJ válido para merchant, validação oficial brasileira (formato, normalização, dígitos verificadores), normalização antes de validação/persistência, rejeição 422 antes de persistência, validação como regra de domínio sem infra/DB/HTTP, unicidade mantida sobre forma normalizada.
- All items pass. Spec ready for `/speckit.plan`.
