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

## Testes

```bash
docker compose exec app composer test
# ou
docker compose exec app ./vendor/bin/phpunit
```

## GitHub

Repositório público: `https://github.com/NicolasPereira/payments-api-hyperf`

```bash
git remote add origin git@github.com:NicolasPereira/payments-api-hyperf.git
git push -u origin main
```

## Licença

Apache-2.0 (herdado do skeleton Hyperf)
