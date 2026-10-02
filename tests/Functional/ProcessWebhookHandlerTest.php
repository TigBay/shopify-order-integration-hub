<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WebhookInboxEntry;
use App\Message\ProcessWebhook;
use App\MessageHandler\ProcessWebhookHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ProcessWebhookHandlerTest extends KernelTestCase
{
    public function testHandlingMarksEntryAsProcessed(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $entry = new WebhookInboxEntry('wh-handler-1', 'app/scopes_update', '{}');
        $entityManager->persist($entry);
        $entityManager->flush();

        $handler = self::getContainer()->get(ProcessWebhookHandler::class);
        $handler(new ProcessWebhook($entry->getId()));
        $entityManager->refresh($entry);

        self::assertTrue($entry->isProcessed());
    }

    public function testHandlingIsIdempotentOnRedelivery(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();

        $entry = new WebhookInboxEntry('wh-handler-2', 'app/scopes_update', '{}');
        $entityManager->persist($entry);
        $entityManager->flush();

        $handler = self::getContainer()->get(ProcessWebhookHandler::class);
        $handler(new ProcessWebhook($entry->getId()));
        $firstProcessedAt = $connection->fetchOne(
            'SELECT processed_at FROM webhook_inbox WHERE id = ?',
            [$entry->getId()]
        );

        // Simulate redelivery of the same message — must not throw or change state.
        $handler(new ProcessWebhook($entry->getId()));

        self::assertNotNull($firstProcessedAt);
        self::assertSame(
            $firstProcessedAt,
            $connection->fetchOne('SELECT processed_at FROM webhook_inbox WHERE id = ?', [$entry->getId()])
        );
    }

    public function testUnknownEntryIdThrows(): void
    {
        self::bootKernel();

        $handler = self::getContainer()->get(ProcessWebhookHandler::class);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $handler(new ProcessWebhook(999999));
    }

    public function testUnknownTopicIsUnrecoverableAndStaysUnprocessed(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $entry = new WebhookInboxEntry('topic-test-1', 'foo/bar', '{"id":2}');
        $entityManager->persist($entry);
        $entityManager->flush();

        $handler = self::getContainer()->get(ProcessWebhookHandler::class);
        try {
            $handler(new ProcessWebhook($entry->getId()));
        } catch (UnrecoverableMessageHandlingException) {
        }

        $entityManager->refresh($entry);
        self::assertFalse($entry->isProcessed());
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('TRUNCATE TABLE webhook_inbox');

        parent::tearDown();
    }
}
