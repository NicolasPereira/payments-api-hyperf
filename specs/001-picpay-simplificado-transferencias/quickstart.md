# Quickstart: PicPay Simplificado — Transferências

This guide validates the planned behavior without defining implementation
details. Run it after `/speckit.implement` has completed.

## Prerequisites

- Docker and Docker Compose available.
- Repository checked out on branch `001-picpay-simplificado-transferencias`.
- MySQL and Redis services available through `docker compose`.
- External HTTPS access to the authorizer and notification mocks.

## Start Services

```bash
cp .env.example .env
docker compose up -d --build
docker compose ps
```

The application must be reachable on `http://localhost:9501`. The first
iteration must provide test users and balances through seed/migration data;
there is deliberately no deposit endpoint in this feature.

## Registration Validation

1. Register a common user using a formatted valid CPF.
2. Confirm HTTP `201`, normalized digits-only document (`^[0-9]{11}$`), and balance `0.00`.
3. Register a merchant using a formatted valid CNPJ (numeric legacy `11.222.333/0001-81`).
4. Confirm HTTP `201`, normalized digits-only document (`^[0-9]{14}$`), and balance `0.00`.
5. Register a merchant using a formatted valid CNPJ alfanumérico (IN RFB 2.229/2024) `12.ABC.345/01DE-35`.
6. Confirm HTTP `201`, normalized uppercase alphanumeric `12ABC34501DE35` (`^[A-Z0-9]{12}[0-9]{2}$`), and balance `0.00`.
7. Repeat either document with different formatting/case (`529.982.247-25` vs `52998224725`, `12abc34501de35` vs `12ABC34501DE35`) and confirm duplicate rejection (`409` or documented validation error, case-insensitive for CNPJ alfa).
8. Submit invalid format, invalid check digits (CPF tradicional e CNPJ alfa via `ASCII-48` módulo 11), repeated digits, and a common/merchant document mismatch. Confirm HTTP `422` and no persisted user or wallet.

See [`contracts/users.yaml`](contracts/users.yaml) and the `User` invariants in
[`data-model.md`](data-model.md).

## Successful Transfer

With a seeded common payer balance of `100.00` and a payee balance of `0.00`:

```bash
curl -i -X POST http://localhost:9501/transfer \
  -H 'Content-Type: application/json' \
  -d '{"value":"10.00","payer":1,"payee":2}'
```

Expected result:

- HTTP `200` or `201` with transfer status `completed`.
- Payer balance `90.00` and payee balance `10.00`.
- One pending Outbox notification created in the same financial transaction.
- Authorizer called before balance mutation.

## Rejection Scenarios

Run the transfer request with these variations and verify no balance change:

- payer balance below the requested value: `422`;
- merchant as payer: `403` or `422`;
- payer/payee missing: `404`;
- payer equal to payee: `422`;
- `value` as a JSON number, zero, negative, malformed, or more than two
  fractional digits: `422`;
- authorizer denied, malformed, unavailable, or timed out: documented `403`,
  `502`, or `503`.

## Idempotency Window (MVP Limitation — T065)

Send the same transfer request twice within three minutes. The second request
must return the stored result and must not create a second debit or credit.
Inspect telemetry for an idempotency hit. Also document the accepted MVP risk:
two intentional equal transfers for the same payer/payee/value within the
window share the same internal fingerprint.

### Limitação Idempotência Hash 3min (Research Decision 3)

- **Fingerprint**: `hash(payer+payee+value)` → `sha256("{payer}:{payee}:{value}")` armazenado em Redis com `SET NX EX 180` atômico.
- **TTL curto 180s**: após expiração, repetição idêntica será re-executada (novo débito/crédito). Perda de Redis remove janela mas mantém audit MySQL `transfers` + `notification_outbox`.
- **Falso positivo**: duas transferências intencionais iguais (mesmo payer/payee/value) dentro de 3min retornam mesmo resultado sem segundo débito — risco aceito para MVP per `spec.md:FR-017` Clarification C.
- **Evolução recomendada**: Idempotency-Key do cliente + TTL longo + persistência MySQL (spec clarification + compatibility plan).

### Telemetry (hits/misses)

- **RedisIdempotencyStore** emite structured logs:
  - `idempotency hit` com `key` quando chave existe (segundachamada dentro da janela)
  - `idempotency miss` com `key` quando chave não existe
  - `idempotency reserved` / `idempotency result stored` para criação
- **ExecuteTransferUseCase** emite:
  - `idempotency hit cached result` com `fingerprint` + `correlation_id`
  - `idempotency duplicate without cached result` quando NX falha sem resultado armazenado
  - Métricas (debug): `metrics idempotency_hit_total` / `metrics idempotency_miss_total` via logger `debug` com `code` = fingerprint prefix
- **Observabilidade**: filtrar logs `structured.log` por `idempotency` ou `metrics idempotency_*` e correlacionar via `correlation_id` + OTEL `trace_id`/`span_id`.
- **Exemplo verificação em Docker**:
  ```bash
  curl -i -X POST http://localhost:9501/transfer -H 'Content-Type: application/json' -d '{"value":"10.00","payer":1,"payee":2}'
  curl -i -X POST http://localhost:9501/transfer -H 'Content-Type: application/json' -d '{"value":"10.00","payer":1,"payee":2}' # deve ser hit
  docker compose exec -T redis redis-cli keys "idempotency:transfer:*"
  docker compose exec -T redis redis-cli ttl "idempotency:transfer:<hash>"
  docker compose logs app | grep -i idempotency
  ```

## Notification Resilience

Force the notification provider to return an error or timeout after a transfer
commits. Verify:

- transfer remains `completed`;
- payer and payee balances remain changed exactly once;
- Outbox status becomes `pending` for retry and then `sent`, or `failed` after
  three attempts;
- logs and traces include correlation context without secrets or unnecessary
  PII.

## Quality Gates

```bash
docker compose exec app composer test
docker compose exec app composer analyse
docker compose exec app ./vendor/bin/php-cs-fixer fix --dry-run --diff
```

The CI-equivalent checks, relevant integration/contract tests, and constitution
review must pass before the feature PR is eligible for merge.
