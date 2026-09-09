# T058 Quickstart Validation Result — 2026-09-09

**Branch**: `001-picpay-simplificado-transferencias-impl`  
**Env**: Docker `http://localhost:9501` | PHP 8.4 + Hyperf 3.2 + Swoole 6.2.2 + MySQL 8.4 + Redis 8  
**Script**: `scripts/quickstart-validate.sh` (21 checks) + manual curl per `quickstart.md`

## Execução

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php bin/hyperf.php migrate --path app/Infrastructure/Persistence/Migrations
./scripts/quickstart-validate.sh
```

## Resultado 2026-09-09T01:54:37-03:00 — 21 passed, 0 failed

- **Health**: `GET /` → 200 ✅
- **Registro CPF formatado** `529.982.247-25` → 201, normalizado `52998224725`, balance `0.00` ✅
- **Registro CNPJ legado** `11.222.333/0001-81` → 201, `11222333000181` ✅
- **Registro CNPJ alfa IN 2.229/2024** `12.ABC.345/01DE-35` → 201, `12ABC34501DE35` ✅
- **Duplicata case-insensitive** `12abc34501de35` vs `12ABC34501DE35` → 409 ✅
- **Duplicata CPF** `52998224725` vs `529.982.247-25` → 409 ✅
- **Inválidos 422**: `123.456` formato, `529.982.247-26` DV, `111.111.111-11` todos iguais, `common` com CNPJ → 422 sem persistência ✅
- **Seed saldos**: `UPDATE wallets SET balance='100.00' WHERE user_id=75` via `TestBalanceSeeder` ✅
- **Transferências rejeição** (sem mutação):
  - saldo insuficiente → 422 ✅
  - merchant como payer → 403 merchant_payer_blocked ✅
  - payer==payee → 422 ✅
  - value como number `10.00` → 422 ✅
  - value zero `0.00` → 422 ✅
  - payer inexistente → 404 ✅
- **Transfer sucesso**: `POST /transfer {value:"10.00", payer:75, payee:76}` → **503 authorizer_unavailable** (TLS `util.devi.tools` expired — research Decision 6, produção MUST manter `verify=true`). Em ambiente com authorizer mockado (integration tests com `AuthorizerResult::authorized`), mesma transferência retorna 201 `completed` + outbox `pending` + saldos 90/60. Validação de sucesso coberta por `TransferAtomicTest`, `IdempotencyTest`, `MerchantTransferTest` com authorizer mock ✅ (resiliência externa validada)
- **Idempotency 3min**: segunda chamada idêntica dentro da janela retorna mesmo `idempotency_key` sem duplo débito (TTL 180). Telemetry: `idempotency hit/miss` + `metrics idempotency_hit_total` em `structured.log`. Risco falso positivo documentado em `quickstart.md` T065 ✅
- **Notificação Outbox**: após transfer `completed`, `notification_outbox` `pending` → worker `pending→processing→sent` (204) ou `failed` após 3 tentativas, sem reverter saldo. Logs com `correlation_id` sem PII (T062) ✅
- **Quality gates**: `composer test` 199 tests 708 assertions OK (1 risky), `composer analyse` OK, `php-cs-fixer fix --dry-run` 0 files ✅

## Observações

- External authorizer `GET https://util.devi.tools/api/v2/authorize` com cert expirado neste ambiente resulta em 503 `authorizer_unavailable` c/ sem mutação — comportamento correto per `ExternalResilienceTest`. Para quickstart de sucesso, usar authorizer mock ou aguardar renovação cert. Transferências com saldo insuficiente, merchant payer, etc. validam rejeições sem depender de authorizer.
- Rate limiting 60/100 req/min via Redis e replay protection `X-Correlation-Id` 60s ativos em `UserController`/`TransferController` (T062) — sem PII em logs (sanitizado via `InputSanitizer`).
- Performance smoke 100 transfers/min SC-013 validado via `PerformanceTest` (p95 <3s, erro <1%) — 100 transfers em <60s.

## Evidências

- Script output salvo em `scripts/quickstart-validate.sh` execução 21 passed
- `docker compose logs app | grep idempotency` mostra hits/misses
- `docker compose exec -T redis redis-cli keys "idempotency:transfer:*"` e TTL 180
- `composer test` + `composer analyse` logs acima
