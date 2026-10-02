# Order & Inventory Integration Hub

[![CI](https://github.com/TigBay/shopify-order-integration-hub/actions/workflows/ci.yml/badge.svg)](https://github.com/TigBay/shopify-order-integration-hub/actions/workflows/ci.yml)

Symfony middleware that receives Shopify orders via webhook, enriches them with ERP data, and writes the results back via metafields.

**Status:** 🚧 actively in development – M2 (webhook receiver & async processing) complete

## Problem & Business Scenario

A DTC brand on Shopify Plus with an ERP system needs three things: orders automatically pushed to the ERP, the ERP order number and expected ship date written back to the order, and inventory levels synced without double-counting.

## Tech Stack

PHP 8.4+, Symfony 8.1 (Messenger, HttpClient), Doctrine ORM + Migrations, PostgreSQL 16 (Docker), Shopify GraphQL Admin API, Client Credentials Grant.

## Features

- [x] Shopify app configuration (scopes for orders/products/inventory)
- [x] Client Credentials Grant + `TokenProvider` with Symfony Cache (23h TTL)
- [x] `GraphqlClient` against the Admin API (verified with `shop { name currencyCode }`)
- [x] Webhook receiver with HMAC verification and inbox deduplication (M2)
- [x] Idempotent processing via Symfony Messenger (M2)
- [ ] ERP enrichment (mocked via WireMock) + write-back via `metafieldsSet` (P1-A)
- [ ] Inventory sync, bulk import, reconciliation (P1-B)

## Quickstart

```bash
docker compose up -d        # PostgreSQL 16
composer install
# create .env.local with SHOPIFY_SHOP, SHOPIFY_CLIENT_ID, SHOPIFY_CLIENT_SECRET
bin/console doctrine:migrations:migrate -n
bin/console app:shopify:token        # verify credentials
bin/console app:shopify:shop-info    # verify API access

# in a second terminal: worker for async webhook processing
bin/console messenger:consume async -vv
```

## Tests

```bash
APP_ENV=test bin/console doctrine:database:create --if-not-exists
APP_ENV=test bin/console doctrine:migrations:migrate -n
php bin/phpunit
```

## Coding Standard

Symfony coding standard via PHP-CS-Fixer (`@Symfony`), checked in CI.

```bash
vendor/bin/php-cs-fixer fix          # format the codebase
git config core.hooksPath .githooks  # once per clone: check staged files before each commit
```

## Design Decisions

- [ADR 0001: Auth Strategy (Client Credentials Grant)](docs/adr/0001-auth-strategy.md)

## Roadmap

Next up: P1-A – ERP enrichment (mocked via WireMock) and write-back of ERP order number and ship date via `metafieldsSet`.

## Open ToDos

- `customers/redact` reads payloads with PostgreSQL JSONB operators, so the redaction is PostgreSQL-only. Storing customer/order IDs in indexed columns would make it portable and faster; deferred until data volume warrants it.
