<?php

declare(strict_types=1);

namespace App\Shopify;

use App\Entity\WebhookInboxEntry;
use App\Message\ProcessWebhook;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class WebhookInbox
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface    $messageBus,
    )
    {
    }

    /**
     * Returns true for a new delivery (recorded and dispatched), false for a duplicate.
     */
    public function receive(string $webhookId, string $topic, string $payload): bool
    {
        // Atomic only because the Doctrine transport shares this DB connection;
        // with Redis/AMQP the dispatch would no longer be part of this transaction.
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $entry = $this->record($webhookId, $topic, $payload);

            if ($entry !== null) {
                $this->messageBus->dispatch(new ProcessWebhook($entry->getId() ?? throw new LogicException('Entry ID must be set after flush.')));
            }

            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $entry !== null;
    }

    /**
     * Returns null if this webhook_id was already recorded (duplicate delivery).
     */
    private function record(string $webhookId, string $topic, string $payload): ?WebhookInboxEntry
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
