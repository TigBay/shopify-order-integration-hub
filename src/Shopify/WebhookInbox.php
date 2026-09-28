<?php

declare(strict_types=1);

namespace App\Shopify;

use App\Entity\WebhookInboxEntry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WebhookInbox
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Records a webhook delivery. Returns null if this webhook_id was already
     * recorded (duplicate delivery), so the caller can ack without reprocessing.
     */
    public function record(string $webhookId, string $topic, string $payload): ?WebhookInboxEntry
    {
        $entry = new WebhookInboxEntry($webhookId, $topic, $payload);
        $this->entityManager->persist($entry);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->entityManager->detach($entry);

            return null;
        }

        return $entry;
    }
}
