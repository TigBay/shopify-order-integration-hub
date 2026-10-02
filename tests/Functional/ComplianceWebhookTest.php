<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WebhookInboxEntry;
use App\Message\ProcessWebhook;
use App\MessageHandler\ProcessWebhookHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ComplianceWebhookTest extends KernelTestCase
{
    public function testCustomerRedactRedactsMatchingOrdersOnly(): void
    {
        $a = $this->createEntry(
            'wh-order-a',
            'orders/create',
            [
                'id' => '1001',
                'customer' => [
                    'id' => '42',
                    'email' => 'a@example.com',
                ],
            ]
        );
        $payloadB = [
            'id' => '1002',
            'customer' => [
                'id' => '7',
                'email' => 'b@example.com',
            ],
        ];
        $b = $this->createEntry(
            'wh-order-b',
            'orders/create',
            $payloadB
        );
        $c = $this->createEntry(
            'wh-order-c',
            'orders/create',
            [
                'id' => '1003',
                'customer' => null,
            ]
        );
        $r = $this->createEntry(
            'wh-redact',
            'customers/redact',
            [
                'shop_domain' => 'test.myshopify.com',
                'customer' => ['id' => 42, 'email' => 'a@example.com'],
                'orders_to_redact' => [1003],
            ]
        );

        $this->process($r);

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertNotNull(
            $connection->fetchOne('SELECT processed_at FROM webhook_inbox WHERE id = ?', [$r->getId()]),
            'redacting the request must not release its claim',
        );

        self::assertSame(WebhookInboxEntry::REDACTED_PAYLOAD, $this->payloadOf($a));
        self::assertSame(WebhookInboxEntry::REDACTED_PAYLOAD, $this->payloadOf($c));
        self::assertSame(WebhookInboxEntry::REDACTED_PAYLOAD, $this->payloadOf($r));

        self::assertSame(json_encode($payloadB, \JSON_THROW_ON_ERROR), $this->payloadOf($b));

        self::assertStringNotContainsString('a@example.com', $this->payloadOf($r));
    }

    private function createEntry(string $webhookId, string $topic, array $payload): WebhookInboxEntry
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $entry = new WebhookInboxEntry($webhookId, $topic, json_encode($payload, \JSON_THROW_ON_ERROR));
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    private function process(WebhookInboxEntry $entry): void
    {
        $handler = self::getContainer()->get(ProcessWebhookHandler::class);
        $handler(new ProcessWebhook($entry->getId()));
    }

    private function payloadOf(WebhookInboxEntry $entry): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();

        return $connection->fetchOne('SELECT payload FROM webhook_inbox WHERE id = ?', [$entry->getId()]);
    }

    public function testCustomerRedactWithoutOrdersToRedact(): void
    {
        self::bootKernel();

        $payloadA = ['id' => 1001, 'customer' => ['id' => 42, 'email' => 'a@example.com']];
        $payloadB = ['id' => 1002, 'customer' => ['id' => 7, 'email' => 'b@example.com']];
        $payloadC = ['id' => 1003, 'customer' => null];   // gast order

        $a = $this->createEntry('wh-order-a', 'orders/create', $payloadA);
        $b = $this->createEntry('wh-order-b', 'orders/create', $payloadB);
        $c = $this->createEntry('wh-order-c', 'orders/create', $payloadC);
        $r = $this->createEntry('wh-redact', 'customers/redact', [
            'shop_domain' => 'test.myshopify.com',
            'customer' => ['id' => 42, 'email' => 'a@example.com'],
            'orders_to_redact' => [],
        ]);

        $this->process($r);

        self::assertSame(WebhookInboxEntry::REDACTED_PAYLOAD, $this->payloadOf($a));
        self::assertSame(json_encode($payloadB, \JSON_THROW_ON_ERROR), $this->payloadOf($b));
        self::assertSame(json_encode($payloadC, \JSON_THROW_ON_ERROR), $this->payloadOf($c));
        self::assertSame(WebhookInboxEntry::REDACTED_PAYLOAD, $this->payloadOf($r));
    }

    public function testShopRedactDeletesEverythingExceptItself(): void
    {
        self::bootKernel();

        $this->createEntry('wh-order-a', 'orders/create', ['id' => 1001, 'customer' => ['id' => 42, 'email' => 'a@example.com']]);
        $this->createEntry('wh-order-b', 'orders/create', ['id' => 1002, 'customer' => ['id' => 7, 'email' => 'b@example.com']]);
        $this->createEntry('wh-order-c', 'orders/create', ['id' => 1003, 'customer' => null]);
        $s = $this->createEntry('wh-shop-redact', 'shop/redact', ['shop_id' => 954889, 'shop_domain' => 'test.myshopify.com']);

        $this->process($s);

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM webhook_inbox'));

        self::assertSame($s->getId(), (int) $connection->fetchOne('SELECT id FROM webhook_inbox'));

        self::assertSame(WebhookInboxEntry::REDACTED_PAYLOAD, $this->payloadOf($s));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('TRUNCATE TABLE webhook_inbox');

        parent::tearDown();
    }
}
