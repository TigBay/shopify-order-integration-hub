<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookInboxEntry;
use App\Shopify\TokenProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'shop/redact')]
final readonly class ShopRedactHandler implements WebhookTopicHandler
{
    public function __construct(private Connection $connection, private TokenProvider $tokenProvider)
    {
    }

    public function handle(WebhookInboxEntry $entry): void
    {
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder
            ->delete('webhook_inbox')
            ->where('id <> :id')
            ->setParameter('id', $entry->getId());
        $queryBuilder->executeStatement();

        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder
            ->update('webhook_inbox')
            ->set('payload', ':redacted')
            ->where('id = :id')
            ->setParameters(
                [
                    'id' => $entry->getId(),
                    'redacted' => WebhookInboxEntry::REDACTED_PAYLOAD,
                ]
            );
        $queryBuilder->executeStatement();

        $this->tokenProvider->forgetToken();
    }
}
