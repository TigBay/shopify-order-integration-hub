# 0002: ERP Call Inside the Claim Transaction

## Status
Accepted (2026-10-12)

## Context
An `orders/create` message goes through these steps in
`ProcessWebhookHandler`: the entry in `webhook_inbox` is claimed with an
atomic update (`processed_at` is set), `OrdersCreateHandler` then creates the
order in the ERP, and finally `OrderMetafieldWriter` writes the ERP order
number and the expected ship date back to Shopify via `metafieldsSet`.

Symfony Messenger delivers messages at least once. If a handler throws, the
message is redelivered, or moved straight to the `failed` transport when the
exception is an `UnrecoverableMessageHandlingException`. A failure at any
step must therefore leave the system in a state from which a retry is safe:
no lost orders, no duplicate ERP orders.

## Decision
The ERP call and the Shopify write-back run inside the transaction that holds
the claim. An entry counts as processed only after both steps have succeeded.

- A failure at any point rolls the claim back, so the message is redelivered
  and processed again from the start.
- There is no intermediate state such as "created in the ERP but not written
  back" that would have to be tracked and cleaned up separately. An entry is
  either unprocessed or fully processed.

## Consequences
- The database connection is held open during two HTTP requests. The ERP
  client has a 5-second idle timeout (`erp.client`); the Shopify client
  currently uses the default idle timeout (`default_socket_timeout`, 60
  seconds). A dedicated, shorter timeout for Shopify is a possible follow-up.
- An HTTP request cannot be rolled back. If the write-back fails after a
  successful ERP call, the order already exists in the ERP while the rollback
  only releases the claim.
- The retry therefore repeats the ERP call. This decision requires that the
  ERP does not create a second order for a repeated `Idempotency-Key` (the
  `webhook_id`). The WireMock stub used in development does not implement
  this; it is an assumption about the real ERP.
- The exception class decides what happens next. A `RuntimeException` (for
  example a network error, a 5xx or 408/429 response from the ERP, or
  Shopify's `INTERNAL_ERROR`) leads to a retry. An
  `UnrecoverableMessageHandlingException` (for example an invalid payload, any
  other 4xx response, or Shopify's `INVALID_VALUE`) skips the retries and goes
  straight to the `failed` transport.

## Alternatives considered
**Two messages with a status field** (for example `erp_sent_at` on the inbox
entry). The first message creates the order in the ERP and stores the result;
the second writes it back to Shopify. A Shopify failure would then not repeat
the ERP call, and no database connection would be held during an HTTP request.

Rejected for now because it adds state (a column and a migration), a second
message with its own handler, and new edge cases, such as the ERP result being
stored but the second message getting lost. At the current volume and scope,
that cost is not justified while the idempotency key already protects against
duplicate ERP orders.

Revisit this decision if the ERP or Shopify respond slowly enough that the open
transaction becomes a bottleneck, if throughput grows, or if an ERP without
idempotency support has to be connected.
