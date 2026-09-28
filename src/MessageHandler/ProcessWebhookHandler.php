<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\WebhookInboxEntry;
use App\Message\ProcessWebhook;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessWebhookHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ProcessWebhook $message): void
    {
        $entry = $this->entityManager->find(WebhookInboxEntry::class, $message->webhookInboxEntryId);

        if (!$entry instanceof WebhookInboxEntry) {
            throw new \RuntimeException(\sprintf('Webhook inbox entry %d not found.', $message->webhookInboxEntryId));
        }

        if ($entry->isProcessed()) {
            // Already handled — safe no-op on redelivery.
            return;
        }

        $entry->markProcessed();
        $this->entityManager->flush();
    }
}
