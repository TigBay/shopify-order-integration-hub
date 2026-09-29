<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\WebhookInboxEntry;
use App\Message\ProcessWebhook;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Throwable;
use function sprintf;

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
            throw new UnrecoverableMessageHandlingException(
                sprintf('Webhook inbox entry %d not found.', $message->webhookInboxEntryId)
            );
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
                ->setParameter('now', new DateTimeImmutable(), Types::DATETIME_IMMUTABLE);

            $claimed = $queryBuilder->getQuery()->execute();

            if ($claimed === 0) {
                $connection->commit();

                return; // already processed or claimed by another worker
            }

            // TODO ERP Call

            $connection->commit();
        } catch (Throwable $throwable) {
            $connection->rollBack();
            throw $throwable;
        }
    }
}
