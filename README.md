# payments-api-hyperf

API de pagamentos construída com **Hyperf 3.2** (PHP 8.4), **MySQL 8.4** e **Redis 8**, totalmente dockerizada.

> Skeleton oficial Hyperf: https://hyperf.wiki/3.1/#/en/quick-start/installation

## Stack

- **PHP**: 8.4 (imagem `hyperf/hyperf:8.4-alpine-v3.22-swoole`)
- **Hyperf**: 3.2.0 (`hyperf/hyperf-skeleton`)
- **MySQL**: 8.4 (porta `3306`)
- **Redis**: 8-alpine (porta `6379`)
- **Swoole**: 6.2.2
- **Server**: porta `9501`

## Estrutura

```
.
├── Dockerfile          # Produção (8.4-alpine-v3.22-swoole)
├── dev.Dockerfile      # Desenvolvimento (com usuário application)
├── docker-compose.yml  # app + mysql + redis
├── .env.example
├── config/autoload/databases.php
├── config/autoload/redis.php
└── app/Controller/IndexController.php
```

## Pré-requisitos

- Docker 29+ e Docker Compose v5+
- Git + SSH configurado para `git@github.com:NicolasPereira`

## Como rodar

```bash
# 1. Clone
git clone git@github.com:NicolasPereira/payments-api-hyperf.git
cd payments-api-hyperf

# 2. Env
cp .env.example .env
# ajuste DB_HOST=mysql e REDIS_HOST=redis (já configurado por padrão)

# 3. Suba os containers
docker compose up -d --build

# 4. Verifique
curl http://localhost:9501/
# {"method":"GET","message":"Hello Hyperf."}

# Logs
docker logs -f payments-api-hyperf
docker compose ps

# MySQL
docker exec -it payments-api-hyperf-mysql mysql -u hyperf -psecret -e "SELECT VERSION();"

# Redis
docker exec -it payments-api-hyperf-redis redis-cli ping
```

## Configuração

`.env` padrão (já alinhado com `docker-compose.yml`):

```env
APP_NAME=payments-api-hyperf
APP_ENV=dev

DB_DRIVER=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=hyperf
DB_USERNAME=hyperf
DB_PASSWORD=secret

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_DB=0
```

`composer.json` name: `nicolaspereira/payments-api-hyperf`

## Comandos úteis

```bash
docker compose exec app php bin/hyperf.php start
docker compose exec app composer install
docker compose exec app php bin/hyperf.php gen:model
docker compose down -v   # remove volumes
```

## Quickstart — PicPay Simplificado Transferências (001)

Feature branch: `001-picpay-simplificado-transferencias-impl` | Spec: `specs/001-picpay-simplificado-transferencias/spec.md`

Endpoints principais:
- `POST /users` — cadastro common (CPF `^[0-9]{11}$`) e merchant (CNPJ `^[A-Z0-9]{12}[0-9]{2}$` IN 2.229/2024) com normalização (strip `.-/ ` + uppercase), DV módulo 11 `ASCII-48`, carteira `0.00`.
- `POST /transfer` — `common→common` e `common→merchant`; `merchant` como payer → 403; value string exata `"10.00"`, transação atômica com lock `FOR UPDATE` asc `user_id`, authorizer `GET /v2/authorize` antes de mutação, Outbox `pending→sent` com retry ≤3.

Limitação idempotência (MVP):
- Fingerprint `sha256(payer:payee:value)` em Redis `SET NX EX 180` (3min). Repetição dentro da janela retorna mesmo resultado sem duplo débito; duas transferências intencionais iguais na janela têm falso positivo; TTL expirado re-executa; perda de Redis mantém audit MySQL. Telemetry `idempotency hit/miss` + `metrics idempotency_*` em logs estruturados. Detalhes em `specs/.../quickstart.md` (T065).

Segurança (Constitution V, T062):
- `strict_types`, validação boundaries em Controllers, rate limiting 60 req/min `/users` e 100 req/min `/transfer` via Redis, replay protection via `X-Correlation-Id` 60s, sem PII/secrets em logs (sanitizado), queries parametrizadas.

Validação quickstart completa:
```bash
# valida CPF/CNPJ alfa, transferências, idempotency 3min, resiliência
./scripts/quickstart-validate.sh
# ou manual per specs/001-picpay-simplificado-transferencias/quickstart.md
docker compose exec -T mysql mysql -u hyperf -psecret -e "SELECT * FROM users; SELECT balance FROM wallets;" hyperf
```

Seeds de saldo para testes (sem endpoint /deposit per spec):
```bash
# via helper
docker compose exec app php -r 'require "vendor/autoload.php"; \Database\Seeders\TestBalanceSeeder::seed(1,"100.00");'
# ou direto MySQL: UPDATE wallets SET balance="100.00" WHERE user_id=1;
```
Helper: `database/seeders/TestBalanceSeeder.php`.

## Testes

```bash
docker compose exec app composer test          # unit + integration + contract (co-phpunit)
docker compose exec app composer analyse       # phpstan
docker compose exec app ./vendor/bin/php-cs-fixer fix --dry-run --diff
# quickstart script
./scripts/quickstart-validate.sh
# performance smoke 100/min (SC-013) per test/Integration/PerformanceTest.php
docker compose exec app ./vendor/bin/phpunit --filter PerformanceTest
# edge cases
docker compose exec app ./vendor/bin/phpunit --filter EdgeCasesTest
```

Migrations:
```bash
docker compose exec app php bin/hyperf.php migrate --path app/Infrastructure/Persistence/Migrations
```

## GitHub

Repositório público: `https://github.com/NicolasPereira/payments-api-hyperf`

```bash
git remote add origin git@github.com:NicolasPereira/payments-api-hyperf.git
git push -u origin main
```

## Licença

Apache-2.0 (herdado do skeleton Hyperf)
