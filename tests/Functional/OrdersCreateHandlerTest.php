<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WebhookInboxEntry;
use App\Erp\ErpClient;
use App\Message\ProcessWebhook;
use App\MessageHandler\ProcessWebhookHandler;
use App\Webhook\OrdersCreateHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

final class OrdersCreateHandlerTest extends KernelTestCase
{
    public function testOrderIsSentToErpAndMarkedProcessed(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();

        $payload = [
            'id' => 450789469,
            'name' => '#1001',
            'currency' => 'EUR',
            'email' => 'kunde@example.com',
            'customer' => ['id' => 42, 'first_name' => 'Erika'],
            'line_items' => [
                ['sku' => 'ABC-1', 'quantity' => 2, 'title' => 'T-Shirt'],
                ['sku' => 'XYZ-9', 'quantity' => 1, 'title' => 'Mütze'],
            ],
        ];

        $mockResponse = new MockResponse('{"erpOrderNumber":"ERP-1","expectedShipDate":"2026-10-06"}', ['http_code' => 201]);

        $entry = new WebhookInboxEntry('wh-handler-2', 'orders/create', json_encode($payload));
        $entityManager->persist($entry);
        $entityManager->flush();

        $handler = $this->handlerWith($mockResponse);
        $handler(new ProcessWebhook($entry->getId()));

        self::assertNotNull($connection->fetchOne('SELECT processed_at FROM webhook_inbox WHERE id = ?', [$entry->getId()]));

        $options = $mockResponse->getRequestOptions();

        self::assertSame('POST', $mockResponse->getRequestMethod());
        self::assertSame('http://erp.test/api/orders', $mockResponse->getRequestUrl());
        self::assertContains('Idempotency-Key: '.$entry->getWebhookId(), $options['headers']);

        $sent = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(450789469, $sent['shopifyOrderId']);
        self::assertSame([['sku' => 'ABC-1', 'quantity' => 2], ['sku' => 'XYZ-9', 'quantity' => 1]], $sent['lines']);

        // The ERP only needs the order, never the customer's personal data.
        self::assertStringNotContainsString('kunde@example.com', $options['body']);
        self::assertStringNotContainsString('Erika', $options['body']);
    }

    public function testErpFailureReleasesTheClaimSoTheRetryCanProcessAgain(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();

        $payload = [
            'id' => 450789469,
            'name' => '#1001',
            'currency' => 'EUR',
            'line_items' => [['sku' => 'ABC-1', 'quantity' => 2]],
        ];

        $entry = new WebhookInboxEntry('wh-erp-down-1', 'orders/create', json_encode($payload, \JSON_THROW_ON_ERROR));
        $entityManager->persist($entry);
        $entityManager->flush();

        $handler = $this->handlerWith(new MockResponse('', ['http_code' => 503]));

        $thrown = null;
        try {
            $handler(new ProcessWebhook($entry->getId()));
        } catch (ServerExceptionInterface $e) {
            $thrown = $e;
        }

        // 5xx must reach Messenger (so it retries) instead of being swallowed ...
        self::assertInstanceOf(ServerExceptionInterface::class, $thrown);
        // ... and the rollback must have undone the claim, otherwise the retry would be a no-op.
        self::assertNull($connection->fetchOne('SELECT processed_at FROM webhook_inbox WHERE id = ?', [$entry->getId()]));
    }

    private function handlerWith(MockResponse $erpResponse): ProcessWebhookHandler
    {
        $erpClient = new ErpClient(new MockHttpClient($erpResponse, 'http://erp.test'));

        $locator = new ServiceLocator([
            'orders/create' => static fn () => new OrdersCreateHandler($erpClient, new NullLogger()),
        ]);

        return new ProcessWebhookHandler(self::getContainer()->get(EntityManagerInterface::class), $locator);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('TRUNCATE TABLE webhook_inbox');

        parent::tearDown();
    }
}
