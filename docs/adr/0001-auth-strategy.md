# 0001: Auth Strategy – Client Credentials Grant

## Status
Accepted (2026-09-26)

## Context
Shopify offers three auth models: Token Exchange (embedded apps running
inside the Admin iframe), Authorization Code Grant (the classic OAuth flow
for apps installed on third-party merchant stores), and Client Credentials
Grant (server-side apps that only act on stores within their own
organization).

The Order & Inventory Integration Hub is a pure backend service with no
Admin UI, and it only ever talks to our own dev store, never to a
third-party merchant's shop.

## Decision
We use the Client Credentials Grant. The Symfony service exchanges
`client_id` + `client_secret` directly for an access token, with no
redirect flow and no merchant consent step. The token is cached for 23
hours (PSR-6, TTL just under the 24h validity window).

Token Exchange is out of scope because there's no embedded Admin UI.
Authorization Code Grant is out of scope because the app isn't installed
on third-party merchant stores — that grant would only become necessary
once the app is meant for the Shopify App Store or customer rollout.

## Consequences
- Lowest implementation effort of the three models, no OAuth redirect
  handling required.
- Only works as long as the app and the store belong to the same Shopify
  organization — migrating to customer-facing rollout would require
  switching to Authorization Code Grant.
- In practice, scope changes require reinstalling the app on the store; a
  version release alone isn't always enough.
- TTL of 23h instead of 24h avoids race conditions right before token
  expiry.
