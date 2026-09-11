# Tasks: PicPay Simplificado — Transferências

**Input**: Design documents from `/specs/001-picpay-simplificado-transferencias/`
**Prerequisites**: plan.md (required), research.md, data-model.md, spec.md, contracts/, quickstart.md
**Tests**: Constitution II exige test-first (Red→Green→Refactor); domain + integration + contract tests obrigatórios. Toda nova produção deve ter testes falhando antes da implementação.
**Organization**: Tasks organizadas por User Story para entrega incremental e teste independente. Estrutura de código alvo `app/` conforme `plan.md:92`.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Paralelizável (arquivos diferentes, sem dependência)
- **[Story]**: Label da User Story (`[US1]` etc.)
- Caminhos exatos incluídos em cada task

## Dependencies & Execution Order

- **Setup (Phase 1)** → sem dependências
- **Foundational (Phase 2)** → depende de Setup, BLOQUEIA todas as User Stories
- **User Story 3 (P3) Cadastro** → depende de Foundational; BLOQUEIA US1/US2/US4 (pré-requisito de identidade, FR-001..FR-005, FR-020..FR-025)
- **User Story 1 (P1) Transfer common→common** → depende de US3
- **User Story 2 (P2) Transfer common→merchant** → depende de US3 + US1
- **User Story 4 (P3) Resiliência externos** → depende de US1 (extends transfer)
- **Polish** → depende de todas as stories desejadas

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Inicialização Hyperf 3.2 + tooling exigido pela Constitution

- [x] T001 Verificar runtime PHP 8.4 + Hyperf 3.2 + Swoole 6.2.2 + MySQL 8.4 + Redis 8 via `docker-compose.yml` e `composer.json`
- [x] T002 [P] Configurar PHP-CS-Fixer PER-CS (`.php-cs-fixer.php`) e validar `composer fix` dry-run
- [x] T003 [P] Configurar PHPStan (`phpstan.neon.dist`) nível adequado e validar `composer analyse`
- [x] T004 [P] Configurar `config/autoload/` e PSR-4 autoload em `composer.json` para `App\` → `app/`
- [x] T005 Criar estrutura base `app/Domain/`, `app/Application/`, `app/Infrastructure/`, `app/Controller/`, `test/Unit/`, `test/Integration/`, `test/Contract/` per `plan.md:92`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Infra compartilhada que BLOQUEIA qualquer User Story

**⚠️ CRITICAL**: Nenhuma User Story pode começar até esta fase completar

- [x] T006 Criar migrations MySQL para `users`, `wallets`, `transfers`, `notification_outbox` em `app/Infrastructure/Persistence/Migrations/` per `data-model.md:5`
- [x] T007 [P] Implementar `app/Domain/Shared/ValueObject/Money.php` com `DECIMAL(15,2)` exato, sem float, pattern `^[0-9]+\.[0-9]{2}$` per `spec.md:FR-008` e `research.md:Decision 2`
- [x] T008 [P] Implementar hierarquia de Domain Exceptions em `app/Domain/Shared/Exception/DomainException.php` (base `code` + `httpStatus` + `businessCode` para métricas/OTEL/event_tracking) + `app/Domain/User/Exception/InvalidDocumentException.php` / `DuplicateDocumentException.php` / `InvalidUserTypeException.php` + `app/Domain/Wallet/Exception/InsufficientBalanceException.php` + `app/Domain/Transfer/Exception/MerchantPayerNotAllowedException.php` / `SelfTransferException.php` / `TransferValidationException.php` (422) per `spec.md:FR-020..FR-025` e `Constitution IV:96`
- [x] T008b [P] Implementar `app/Infrastructure/Http/ExceptionMapper.php` + `app/Infrastructure/Http/ErrorResponse.php` mapeando `DomainException->businessCode` para envelope `contracts/transfer.yaml:103`/`users.yaml:95` (`code`, `message`, `correlation_id`) + OTEL `exception.type` + `metrics counter domain_exception_total{code}` per `Constitution VI:152`
- [x] T009 [P] Implementar `app/Infrastructure/Persistence/Database.php` helpers de transação + `SELECT FOR UPDATE` ordenado por `user_id` per `plan.md:145`
- [x] T010 [P] Implementar `app/Infrastructure/Cache/RedisIdempotencyStore.php` com `SET NX EX 180` atômico per `research.md:Decision 3`
- [x] T011 [P] Definir ports `app/Domain/Contracts/AuthorizerPort.php` e `app/Domain/Contracts/NotifierPort.php` + `AuthorizerResult`/`NotifyResult` value objects per `research.md:Decision 6`
- [x] T012 [P] Configurar `config/routes.php` com `POST /users` e `POST /transfer` + middleware correlação `X-Correlation-Id` per `plan.md:114`
- [x] T013 Configurar OpenTelemetry SDK bootstrap em `config/autoload/opentelemetry.php` + structured logs com trace context per Constitution VI e `plan.md:68`
- [x] T014 [P] Implementar `app/Infrastructure/External/AuthorizerHttpAdapter.php` (Hyperf Guzzle, TLS verify ON) mapeando `{status:success, data:{authorization:true}}` per `contracts/external-services.md:4`
- [x] T015 [P] Implementar `app/Infrastructure/External/NotifierHttpAdapter.php` (POST `https://util.devi.tools/api/v1/notify` 204) per `contracts/external-services.md:18`

**Checkpoint**: Foundation ready — User Stories podem começar (US3 primeiro)

---

## Phase 3: User Story 3 - Cadastro de usuários e carteira com validação de documento (Priority: P3) — PRÉ-REQUISITO

**Goal**: Cadastro `common` (CPF `^[0-9]{11}$`) e `merchant` (CNPJ `^[A-Z0-9]{12}[0-9]{2}$` IN 2.229/2024) com normalização, hash senha ≥8, carteira `0.00`
**Independent Test**: `POST /users` com CPF `529.982.247-25` → 201 `52998224725`; CNPJ `12.ABC.345/01DE-35` → 201 `12ABC34501DE35`; duplicata `52998224725` → 409; DV inválido → 422 sem persistência (`spec.md:67`)

### Tests for User Story 3 (TDD — escrever ANTES, garantir FAIL)

- [ ] T016 [P] [US3] Unit test CPF normalização/validação em `test/Unit/Domain/User/CpfTest.php` (formatado, DV, todos iguais, `11111111111` → 422)
- [ ] T017 [P] [US3] Unit test CNPJ legado numérico em `test/Unit/Domain/User/CnpjLegacyTest.php` (`11.222.333/0001-81` → `11222333000181`)
- [ ] T018 [P] [US3] Unit test CNPJ alfanumérico IN 2.229/2024 em `test/Unit/Domain/User/CnpjAlfaTest.php` (`12.ABC.345/01DE-35` → `12ABC34501DE35`, `12abc34501de35` case-insensitive, `ASCII-48` pesos 2-9 módulo 11, DV inválido `12ABC34501DE36` → 422)
- [ ] T019 [P] [US3] Unit test compatibilidade tipo-documento em `test/Unit/Domain/User/UserTypeDocumentTest.php` (common+CNPJ → 422, merchant+CPF → 422)
- [ ] T020 [P] [US3] Unit test Email normalização em `test/Unit/Domain/User/EmailTest.php` (case-insensitive `A@b.com` ≡ `a@b.com`, unicidade)
- [ ] T021 [P] [US3] Contract test `POST /users` em `test/Contract/UsersContractTest.php` (201 common/merchant/merchant_alfa, 409 duplicata, 422 formato/DV/tipo)

### Implementation for User Story 3

- [ ] T022 [P] [US3] Implementar `app/Domain/User/ValueObject/DocumentConsumer.php` (encapsula CPF — normalize strip `.-/ `, `^[0-9]{11}$`, rejeita todos iguais, valida DV módulo 11) per `spec.md:FR-020` — identidade de `common`
- [ ] T023 [P] [US3] Implementar `app/Domain/User/ValueObject/DocumentMerchant.php` (encapsula CNPJ — normalize strip `.-/ ` + `uppercase`, `^[A-Z0-9]{12}[0-9]{2}$` IN 2.229/2024, DV `ASCII-48` `A=17...Z=42` pesos 2-9, legacy `^[0-9]{14}$` subset) per `spec.md:FR-020` — identidade de `merchant`
- [ ] T024 [P] [US3] Implementar `app/Domain/User/ValueObject/Document.php` interface + `DocumentType` enum (cpf/cnpj) + `DocumentFactory::for(UserType): Document` (polimorfismo por type, esconde CPF/CNPJ por trás) per `data-model.md:13`
- [ ] T025 [P] [US3] Implementar `app/Domain/User/ValueObject/Email.php` (normalize lowercase, valida formato, unicidade) per `spec.md:FR-019`
- [ ] T026 [P] [US3] Implementar `app/Domain/User/Entity/User.php` + `UserType` enum (common/merchant) agregando Document, Email, passwordHash per `data-model.md:5`
- [ ] T027 [P] [US3] Implementar `app/Domain/Wallet/Entity/Wallet.php` (balance `Money`, `user_id` unique, never negative) per `data-model.md:29`
- [ ] T028 [US3] Implementar `app/Application/User/CreateUserUseCase.php` (validação domínio pura antes de I/O, hash senha `password_hash` `PASSWORD_ARGON2ID` (memory 64MiB, time 4, threads 1) fallback `PASSWORD_BCRYPT` cost 12 + `password_verify`/`needs_rehash`, unicidade document/email) per `spec.md:FR-024`, `research.md:Decision 10` e `plan.md:133`
- [ ] T029 [US3] Implementar `app/Infrastructure/Persistence/UserRepository.php` + `WalletRepository.php` com transação atômica User+Wallet per `plan.md:136` e `data-model.md:113`
- [ ] T030 [US3] Implementar `app/Controller/UserController.php` (thin controller: validate `full_name`, `document`, `email`, `password` min 8, `type`, delega UseCase) per Constitution I
- [ ] T031 [US3] Integration test atomicidade User+Wallet em `test/Integration/UserRegistrationTest.php` (rollback em DV inválido, constraint única) per `plan.md:159`

**Checkpoint**: US3 completo — `POST /users` aceita CPF e CNPJ alfa `12ABC34501DE35` com persistência normalizada, bloqueia duplicatas, rejeita 422 sem efeitos colaterais

---

## Phase 4: User Story 1 - Transferência entre usuários comuns (Priority: P1) 🎯 MVP

**Goal**: `POST /transfer {value:"10.00", payer, payee}` com `common→common`, validação saldo, autorizador, transação atômica, Outbox queued
**Independent Test**: payer 100 → payee 50, `POST /transfer {value:"10.00", payer:1, payee:2}` authorized → 200/201, saldos 90/60, notificação queued (`spec.md:29`)

### Tests for User Story 1 (TDD)

- [ ] T032 [P] [US1] Unit test `Money` parsing/validation em `test/Unit/Domain/Transfer/MoneyTest.php` (string exata `"10.00"`, rejeita `number` 10.0, zero, negativo, `>2` decimais → 422)
- [ ] T033 [P] [US1] Unit test `Transfer` invariants em `test/Unit/Domain/Transfer/TransferTest.php` (payer≠payee, payer must be common, value positive)
- [ ] T034 [P] [US1] Unit test `ExecuteTransferUseCase` com mocks em `test/Unit/Application/Transfer/ExecuteTransferTest.php` (saldo insuficiente → 422, sem mutação, authorize antes de transação)
- [ ] T035 [P] [US1] Contract test `POST /transfer` valid em `test/Contract/TransferContractTest.php` (200/201 completed/queued, 422 saldo, 404 missing, 422 self-transfer)
- [ ] T036 [P] [US1] Integration test transação atômica em `test/Integration/TransferAtomicTest.php` (lock `FOR UPDATE` asc `user_id`, recheck balance, rollback em falha, concorrência 2 transfers simultâneas → sem saldo negativo)

### Implementation for User Story 1

- [ ] T037 [P] [US1] Implementar `app/Domain/Transfer/ValueObject/TransferValue.php` (parse string `"100.00"` → centavos/DECIMAL, valida pattern `^(?!0+\.00$)[0-9]+\.[0-9]{2}$`) per `contracts/transfer.yaml:75`
- [ ] T038 [P] [US1] Implementar `app/Domain/Transfer/Entity/Transfer.php` + `TransferStatus` enum (pending/authorized/completed/failed) + transitions per `data-model.md:58`
- [ ] T039 [US1] Implementar `app/Application/Transfer/ExecuteTransferUseCase.php` fluxo `plan.md:133` (valida payload → idempotency fingerprint `hash(payer+payee+value)` Redis NX → load payer/payee → reject self/404 → balance check → AuthorizerPort → transaction lock wallets asc → recheck → debit/credit → create Transfer → insert Outbox `pending`)
- [ ] T040 [US1] Implementar `app/Infrastructure/Persistence/TransferRepository.php` per `data-model.md:41`
- [ ] T041 [US1] Implementar `app/Infrastructure/Persistence/NotificationOutboxRepository.php` per `data-model.md:74` (pending/processing/sent/failed, attempts ≤3)
- [ ] T042 [US1] Implementar `app/Controller/TransferController.php` (thin, valida `value` string, delega UseCase, mapeia 422/403/404/502/503) per `contracts/transfer.yaml:24`
- [ ] T043 [US1] Integration test idempotency Redis em `test/Integration/IdempotencyTest.php` (2× mesmo `payer+payee+value` em 3min → mesmo resultado sem duplo débito, TTL 180s)

**Checkpoint**: US1 MVP funcional — common→common transfer completa com saldo exato, idempotente, Outbox transacional

---

## Phase 5: User Story 2 - Transferência de usuário comum para lojista (Priority: P2)

**Goal**: `common→merchant` recebe crédito/notificação; `merchant` como payer → 403/422
**Independent Test**: common 100, merchant 0, `POST /transfer` payee=merchant authorized → 90/10 (`spec.md:46`)

### Tests for User Story 2

- [ ] T044 [P] [US2] Contract test common→merchant em `test/Contract/TransferMerchantTest.php` (200/201 saldos 30/30 para 50→10 +20)
- [ ] T045 [P] [US2] Contract test merchant payer rejection em `test/Contract/MerchantPayerRejectionTest.php` (403/422 "lojista não pode enviar")

### Implementation for User Story 2

- [ ] T046 [US2] Estender `app/Application/Transfer/ExecuteTransferUseCase.php` para validar `payer.type == merchant` → throw `app/Domain/Transfer/Exception/MerchantPayerNotAllowedException.php` (extends `DomainException` 403, `businessCode=merchant_payer_blocked`) + `metrics counter merchant_payer_blocked_total` + OTEL `exception.type` per `spec.md:FR-007` e `Constitution VI:164` (Opção A — guard dentro de US2)
- [ ] T047 [US2] Ajustar `app/Domain/Transfer/Entity/Transfer.php` para permitir `payee` merchant e atualizar documentação per `spec.md:FR-006`
- [ ] T048 [US2] Integration test tipo lojista em `test/Integration/MerchantTransferTest.php` (merchant payee credita + notifica, merchant payer bloqueado sem mutação)

**Checkpoint**: US1+US2 funcionais — regra lojista só recebe garantida

---

## Phase 6: User Story 4 - Consulta e resiliência de serviços externos (Priority: P3)

**Goal**: Authorizer `GET /authorize` e Notifier `POST /notify` resilientes, Outbox async 3 retries, sem reversão financeira
**Independent Test**: mock authorizer authorized/denied/timeout/5xx, notifier 204/5xx/timeout → transfer revertida ou completed + Outbox pending/sent/failed (`spec.md:83`)

### Tests for User Story 4

- [ ] T049 [P] [US4] Contract test authorizer em `test/Contract/AuthorizerContractTest.php` (authorized `{status:success, data:{authorization:true}}` → proceed; denied/malformed/5xx/timeout → 403/502/503 sem mutação)
- [ ] T050 [P] [US4] Contract test notifier em `test/Contract/NotifierContractTest.php` (204 → sent; 4xx/5xx/timeout → pending retry, ≤3 attempts, não reverte saldo)
- [ ] T051 [P] [US4] Integration test Outbox worker em `test/Integration/OutboxWorkerTest.php` (commit Transfer+Outbox atômico, worker claim `pending→processing→sent`, lease expiry `processing→pending`, `failed` após 3)

### Implementation for User Story 4

- [ ] T052 [US4] Implementar tratamento authorizer em `app/Application/Transfer/ExecuteTransferUseCase.php` (mapeia não autorizado → 403, malformed/timeout/5xx → 502/503, garante sem mutação antes de transação) per `contracts/external-services.md:4`
- [ ] T053 [US4] Implementar `app/Application/Notification/ProcessNotificationOutboxUseCase.php` (claim pending, chama NotifierPort, registra `last_response`, incrementa `attempts`, `available_at` backoff, `sent`/`pending`/`failed`) per `data-model.md:94`
- [ ] T054 [US4] Implementar `app/Infrastructure/Notification/OutboxWorker.php` (Hyperf Process `config/autoload/processes.php`, polling + `lease_until`, retry ≤3) per `plan.md:116` e `research.md:Decision 5`
- [ ] T055 [US4] Implementar `app/Infrastructure/Notification/OutboxWorkerProcess.php` registration em `config/autoload/processes.php`
- [ ] T056 [US4] Adicionar correlação/tracing em `app/Application/Transfer/ExecuteTransferUseCase.php` e `OutboxWorker.php` (correlation_id, OTEL spans para authorize/transfer/notify) per Constitution VI
- [ ] T057 [US4] Integration test resiliência completa em `test/Integration/ExternalResilienceTest.php` (authorizer indisponível 503 sem débito, notifier timeout mantém completed, idempotency Redis loss mantém audit MySQL)

**Checkpoint**: Todas as transferências resilientes — authorizer bloqueia mutação, notificação fire-and-forget com Outbox

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Validação end-to-end, qualidade e documentação

- [ ] T058 [P] Executar `quickstart.md` validação completa (registro CPF/CNPJ alfa, transferências, idempotency 3min, resiliência) em ambiente Docker `http://localhost:9501`
- [ ] T059 [P] Adicionar seed/migration de saldos para testes (`database/seeders/TestBalanceSeeder.php`) — sem endpoint `/deposit` per `spec.md:Clarification 2026-08-30`
- [ ] T060 [P] Unit tests adicionais edge cases em `test/Unit/Domain/Shared/EdgeCasesTest.php` (value zero/negativo/number, payer==payee, documento lowercase com espaços, email case-insensitive)
- [ ] T061 [P] Performance smoke 100 transfers/min em `test/Integration/PerformanceTest.php` per `spec.md:SC-013`
- [ ] T062 [P] Security hardening em `app/Controller/` (rate limiting, replay protection, input validation boundaries, no PII/secrets em logs) per Constitution V
- [ ] T063 Rodar quality gates: `composer test`, `composer analyse`, `./vendor/bin/php-cs-fixer fix --dry-run --diff` e corrigir violações per `plan.md:165`
- [ ] T064 Atualizar `README.md` e `specs/001-picpay-simplificado-transferencias/checklists/requirements.md` com status final
- [ ] T065 [P] Documentar limitação idempotência hash 3min em `quickstart.md` + telemetry (hits/misses) per `research.md:Decision 3`

---

## Notes

- [P] = pode rodar em paralelo (arquivos diferentes, sem dependência)
- [Story] label mapeia task à User Story para rastreabilidade (US1 P1, US2 P2, US3 P3, US4 P3)
- Cada User Story é independentemente completável e testável (checkpoint)
- Testes DEVEM ser escritos primeiro e FALHAR antes da implementação (Constitution II)
- Commit após cada task ou grupo lógico (Conventional Commits, sem `git add .`): `feat(user): T022 add Cpf value object` etc.
- Parar em qualquer checkpoint para validar story independentemente
- Evitar: tasks vagas, conflitos no mesmo arquivo, dependências cross-story que quebram independência

## Dependencies & Execution Order (Resumo)

```
Setup (T001-T005) → Foundational (T006-T015) → US3 (T016-T031) → US1 (T032-T043) → US2 (T044-T048) → US4 (T049-T057) → Polish (T058-T065)
```

**Paralelismo máximo**: Dentro de cada fase, tasks [P] podem rodar em paralelo. Após Foundational, US3 deve completar antes de US1/US2/US4. Dentro de cada US, tests [P] + models [P] paralelizáveis.

## MVP Scope

- **MVP**: Phase 1 + Phase 2 + Phase 3 (US3 Cadastro) + Phase 4 (US1 Transfer common→common) = entrega mínima demonstrável
- Incrementos: + US2 (merchant) → + US4 (resiliência) → + Polish
