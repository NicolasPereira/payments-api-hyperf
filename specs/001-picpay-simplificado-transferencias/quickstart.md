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
2. Confirm HTTP `201`, normalized digits-only document, and balance `0.00`.
3. Register a merchant using a formatted valid CNPJ.
4. Confirm HTTP `201`, normalized digits-only document, and balance `0.00`.
5. Repeat either document with different formatting and confirm duplicate
   rejection (`409` or the documented validation error).
6. Submit invalid format, invalid check digits, repeated digits, and a
   common/merchant document mismatch. Confirm HTTP `422` and no persisted
   user or wallet.

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

## Idempotency Window

Send the same transfer request twice within three minutes. The second request
must return the stored result and must not create a second debit or credit.
Inspect telemetry for an idempotency hit. Also document the accepted MVP risk:
two intentional equal transfers for the same payer/payee/value within the
window share the same internal fingerprint.

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
