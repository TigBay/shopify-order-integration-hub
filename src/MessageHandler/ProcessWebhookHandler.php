<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\WebhookInboxEntry;
use App\Message\ProcessWebhook;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class ProcessWebhookHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[AutowireLocator('app.webhook_topic_handler')]
        private ContainerInterface $topicHandlers,
    ) {
    }

    public function __invoke(ProcessWebhook $message): void
    {
        $entry = $this->entityManager->find(WebhookInboxEntry::class, $message->webhookInboxEntryId);

        if (!$entry instanceof WebhookInboxEntry) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Webhook inbox entry %d not found.', $message->webhookInboxEntryId));
        }

        if (!$this->topicHandlers->has($entry->getTopic())) {
            throw new UnrecoverableMessageHandlingException(\sprintf('No handler for topic %s found.', $entry->getTopic()));
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $queryBuilder = $this->entityManager->createQueryBuilder();
            $queryBuilder->update(WebhookInboxEntry::class, 'w')
                ->set('w.processedAt', ':now')
                ->where('w.id = :id')
                ->andWhere('w.processedAt IS NULL')
                ->setParameter('id', $message->webhookInboxEntryId)
                ->setParameter('now', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE);

            $claimed = $queryBuilder->getQuery()->execute();

            if (0 === $claimed) {
                $connection->commit();

                return; // already processed or claimed by another worker
            }

            $this->topicHandlers->get($entry->getTopic())->handle($entry);

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();
            throw $throwable;
        }
    }
}
