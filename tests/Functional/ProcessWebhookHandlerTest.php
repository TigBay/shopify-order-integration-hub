<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WebhookInboxEntry;
use App\Message\ProcessWebhook;
use App\MessageHandler\ProcessWebhookHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProcessWebhookHandlerTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('TRUNCATE TABLE webhook_inbox');

        parent::tearDown();
    }

    public function testHandlingMarksEntryAsProcessed(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $entry = new WebhookInboxEntry('wh-handler-1', 'orders/create', '{"id":1}');
        $entityManager->persist($entry);
        $entityManager->flush();

        $handler = self::getContainer()->get(ProcessWebhookHandler::class);
        $handler(new ProcessWebhook($entry->getId()));

        self::assertTrue($entry->isProcessed());
    }

    public function testHandlingIsIdempotentOnRedelivery(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $entry = new WebhookInboxEntry('wh-handler-2', 'orders/create', '{"id":2}');
        $entityManager->persist($entry);
        $entityManager->flush();

        $handler = self::getContainer()->get(ProcessWebhookHandler::class);
        $handler(new ProcessWebhook($entry->getId()));
        $firstProcessedAt = $entry->isProcessed();

        // Simulate redelivery of the same message — must not throw or change state.
        $handler(new ProcessWebhook($entry->getId()));

        self::assertTrue($firstProcessedAt);
        self::assertTrue($entry->isProcessed());
    }

    public function testUnknownEntryIdThrows(): void
    {
        self::bootKernel();

        $handler = self::getContainer()->get(ProcessWebhookHandler::class);

        $this->expectException(\RuntimeException::class);
        $handler(new ProcessWebhook(999999));
    }
}
