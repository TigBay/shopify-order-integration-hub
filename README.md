# Order & Inventory Integration Hub

Symfony middleware that receives Shopify orders via webhook, enriches them with ERP data, and writes the results back via metafields.

**Status:** 🚧 actively in development – M1 (app model & auth) complete

## Problem & Business Scenario

A DTC brand on Shopify Plus with an ERP system needs three things: orders automatically pushed to the ERP, the ERP order number and expected ship date written back to the order, and inventory levels synced without double-counting.

## Tech Stack

PHP 8.4+, Symfony 8.1 (Messenger, HttpClient), Shopify GraphQL Admin API, Client Credentials Grant.

## Features

- [x] Shopify app configuration (scopes for orders/products/inventory)
- [x] Client Credentials Grant + `TokenProvider` with PSR-6 cache (23h TTL)
- [x] `GraphqlClient` against the Admin API (verified with `shop { name currencyCode }`)
- [ ] Webhook receiver with HMAC verification and inbox deduplication (M2)
- [ ] Idempotent processing via Symfony Messenger (M2)
- [ ] ERP enrichment (mocked via WireMock) + write-back via `metafieldsSet` (P1-A)
- [ ] Inventory sync, bulk import, reconciliation (P1-B)

## Quickstart

```bash
composer install
cp .env .env.local   # fill in SHOPIFY_SHOP / SHOPIFY_CLIENT_ID / SHOPIFY_CLIENT_SECRET
bin/console app:shopify:token
bin/console app:shopify:shop-info
```

## Design Decisions

- [ADR 0001: Auth Strategy (Client Credentials Grant)](docs/adr/0001-auth-strategy.md)

## Roadmap

Next up: M2 – webhook receiver with HMAC verification and idempotency.
