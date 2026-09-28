<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\ProcessWebhook;
use App\Shopify\WebhookInbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class WebhookInboxTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('TRUNCATE TABLE webhook_inbox');

        parent::tearDown();
    }

    public function testDispatchFailureRollsBackInboxEntry(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $busFailure = new \Exception('transport down');
        $failingBus = new class($busFailure) implements MessageBusInterface {
            public function __construct(private \Throwable $failure)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw $this->failure;
            }
        };

        $inbox = new WebhookInbox($entityManager, $failingBus);

        $thrown = null;
        try {
            $inbox->receive('wh-atomic-1', 'orders/create', '{"id":1}');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertSame($busFailure, $thrown);
        self::assertSame(0, $this->countEntries('wh-atomic-1'));
    }

    public function testDuplicateIsNotDispatchedAgain(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $spyBus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message, $stamps);
            }
        };

        $inbox = new WebhookInbox($entityManager, $spyBus);

        self::assertTrue($inbox->receive('wh-dup-1', 'orders/create', '{"id":1}'));
        self::assertFalse($inbox->receive('wh-dup-1', 'orders/create', '{"id":1}'));

        self::assertCount(1, $spyBus->dispatched);
        self::assertInstanceOf(ProcessWebhook::class, $spyBus->dispatched[0]);
        self::assertSame(1, $this->countEntries('wh-dup-1'));
    }

    private function countEntries(string $webhookId): int
    {
        return (int) self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM webhook_inbox WHERE webhook_id = ?', [$webhookId]);
    }
}
