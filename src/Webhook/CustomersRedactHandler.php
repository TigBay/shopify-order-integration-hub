<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookInboxEntry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Redacts the stored payloads of a customer's orders. The bulk UPDATE reads the JSON payload
 * with PostgreSQL operators (jsonb, ->, ->>), so this handler is PostgreSQL-only.
 */
#[AsTaggedItem(index: 'customers/redact')]
final readonly class CustomersRedactHandler implements WebhookTopicHandler
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function handle(WebhookInboxEntry $entry): void
    {
        $payload = json_decode($entry->getPayload(), true, flags: \JSON_THROW_ON_ERROR);

        $customerId = (string) $payload['customer']['id'];
        $orderIds = array_map(strval(...), $payload['orders_to_redact'] ?? []);

        $condition = "CAST(payload AS jsonb) -> 'customer' ->> 'id' = :customerId";
        $params = ['redacted' => WebhookInboxEntry::REDACTED_PAYLOAD, 'customerId' => $customerId];
        $types = [];

        if ([] !== $orderIds) {
            $condition .= " OR CAST(payload AS jsonb) ->> 'id' IN (:orderIds)";
            $params['orderIds'] = $orderIds;
            $types['orderIds'] = ArrayParameterType::STRING;
        }

        $this->entityManager->getConnection()->executeStatement(
            "UPDATE webhook_inbox SET payload = :redacted WHERE topic LIKE 'orders/%' AND ($condition)",
            $params,
            $types,
        );

        // The request itself contains the customer's e-mail/phone. Flushing within the handler's
        // transaction only writes the changed column, so processed_at from the claim stays intact.
        $entry->redact();
        $this->entityManager->flush();
    }
}
