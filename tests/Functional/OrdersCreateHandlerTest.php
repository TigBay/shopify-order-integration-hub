<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WebhookInboxEntry;
use App\Erp\ErpClient;
use App\Message\ProcessWebhook;
use App\MessageHandler\ProcessWebhookHandler;
use App\Shopify\GraphqlClient;
use App\Shopify\OrderMetafieldWriter;
use App\Shopify\TokenProvider;
use App\Webhook\OrdersCreateHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

final class OrdersCreateHandlerTest extends KernelTestCase
{
    public static function invalidOrderPayloads(): iterable
    {
        $item = ['sku' => 'ABC-1', 'quantity' => 1];

        yield 'only id' => [['id' => 1], 'name, currency, line_items'];
        yield 'name missing' => [['id' => 1, 'currency' => 'EUR', 'line_items' => [$item]], 'name'];
        yield 'no line items' => [['id' => 1, 'name' => '#1', 'currency' => 'EUR', 'line_items' => []], 'line_items'];
        yield 'line_items is not a list' => [['id' => 1, 'name' => '#1', 'currency' => 'EUR', 'line_items' => 'x'], 'line_items'];
        yield 'second item without sku' => [
            ['id' => 1, 'name' => '#1', 'currency' => 'EUR', 'line_items' => [$item, ['sku' => null, 'quantity' => 2]]],
            'line_items[1].sku',
        ];
    }

    public static function shopifyErpErrors(): iterable
    {
        yield 'invalid value' => [
            ['data' => ['metafieldsSet' => ['metafields' => [], 'userErrors' => [['field' => ['metafields', '0', 'value'], 'message' => 'bad', 'code' => 'INVALID_VALUE']],
            ]]],
            UnrecoverableMessageHandlingException::class,
            false,
        ];
        yield 'internal error' => [
            ['data' => ['metafieldsSet' => ['metafields' => [], 'userErrors' => [['field' => ['metafields', '0', 'value'], 'message' => 'bad', 'code' => 'INTERNAL_ERROR']],
            ]]],
            \RuntimeException::class,
            true,
        ];
    }

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

        $emptyResponse = ['data' => ['metafieldsSet' => ['metafields' => [], 'userErrors' => []]]];

        $erpResponse = new MockResponse('{"erpOrderNumber":"ERP-1","expectedShipDate":"2026-10-06"}', ['http_code' => 201]);
        $shopifyResponse = new MockResponse(json_encode($emptyResponse));

        $entry = new WebhookInboxEntry('wh-handler-2', 'orders/create', json_encode($payload));
        $entityManager->persist($entry);
        $entityManager->flush();

        $handler = $this->handlerWith($erpResponse, $shopifyResponse);
        $handler(new ProcessWebhook($entry->getId()));

        self::assertNotNull($connection->fetchOne('SELECT processed_at FROM webhook_inbox WHERE id = ?', [$entry->getId()]));

        $options = $erpResponse->getRequestOptions();

        self::assertSame('POST', $erpResponse->getRequestMethod());
        self::assertSame('http://erp.test/api/orders', $erpResponse->getRequestUrl());
        self::assertContains('Idempotency-Key: '.$entry->getWebhookId(), $options['headers']);

        $sent = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(450789469, $sent['shopifyOrderId']);
        self::assertSame([['sku' => 'ABC-1', 'quantity' => 2], ['sku' => 'XYZ-9', 'quantity' => 1]], $sent['lines']);

        $shopifyBody = $shopifyResponse->getRequestOptions()['body'];
        self::assertStringContainsString('ERP-1', $shopifyBody);
        self::assertStringContainsString('2026-10-06', $shopifyBody);

        // Neither the ERP nor Shopify needs the customer's personal data
        self::assertStringNotContainsString('kunde@example.com', $options['body']);
        self::assertStringNotContainsString('Erika', $options['body']);
        self::assertStringNotContainsString('kunde@example.com', $shopifyBody);
        self::assertStringNotContainsString('Erika', $shopifyBody);
    }

    private function handlerWith(MockResponse|MockHttpClient $erp, MockResponse|MockHttpClient|null $shopify = null): ProcessWebhookHandler
    {
        $httpClient = $erp instanceof MockHttpClient ? $erp : new MockHttpClient($erp, 'http://erp.test');
        $erpClient = new ErpClient($httpClient);

        $emptyResponse = ['data' => ['metafieldsSet' => ['metafields' => [], 'userErrors' => []]]];

        $shopify ??= new MockResponse(json_encode($emptyResponse));
        $shopifyHttpClient = $shopify instanceof MockHttpClient ? $shopify : new MockHttpClient($shopify);

        $cache = new ArrayAdapter();
        $cache->get(TokenProvider::CACHE_KEY, fn () => 'test-token');   // Token vorab im Cache
        $tokenProvider = new TokenProvider(new MockHttpClient(), $cache, 'test.myshopify.com', 'id', 'secret');
        $graphql = new GraphqlClient($shopifyHttpClient, $tokenProvider, 'test.myshopify.com');
        $writer = new OrderMetafieldWriter($graphql);

        $locator = new ServiceLocator([
            'orders/create' => static fn () => new OrdersCreateHandler($erpClient, new NullLogger(), $writer),
        ]);

        return new ProcessWebhookHandler(self::getContainer()->get(EntityManagerInterface::class), $locator);
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
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // 5xx must reach Messenger (so it retries) instead of being swallowed ...
        self::assertInstanceOf(ServerExceptionInterface::class, $thrown);
        // ... and the rollback must have undone the claim, otherwise the retry would be a no-op.
        self::assertNull($connection->fetchOne('SELECT processed_at FROM webhook_inbox WHERE id = ?', [$entry->getId()]));
    }

    #[DataProvider('shopifyErpErrors')]
    public function testFailedWriteBackReleasesTheClaim(array $shopifyResponse, string $expectedException, bool $retryable): void
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

        $entry = new WebhookInboxEntry('wh-shopify-fail-1', 'orders/create', json_encode($payload, \JSON_THROW_ON_ERROR));
        $entityManager->persist($entry);
        $entityManager->flush();

        $erpHttp = new MockHttpClient(
            new MockResponse('{"erpOrderNumber":"ERP-1","expectedShipDate":"2026-10-06"}', ['http_code' => 201]),
            'http://erp.test',
        );
        $shopifyHttp = new MockHttpClient(new MockResponse(json_encode($shopifyResponse, \JSON_THROW_ON_ERROR)));

        $handler = $this->handlerWith($erpHttp, $shopifyHttp);

        $thrown = null;
        try {
            $handler(new ProcessWebhook($entry->getId()));
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // A rollback resets the claim and controls the retry and failure exceptions
        self::assertInstanceOf($expectedException, $thrown);
        // ... and must have undone the claim, otherwise the retry would be a no-op.
        self::assertNull($connection->fetchOne('SELECT processed_at FROM webhook_inbox WHERE id = ?', [$entry->getId()]));
        // The ERP call already happened and cannot be rolled back; the retry repeats it,
        // which is why the request carries an Idempotency-Key.
        self::assertSame(1, $erpHttp->getRequestsCount());
        self::assertSame(1, $shopifyHttp->getRequestsCount());
        if ($retryable) {
            self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $thrown);
        }
    }

    #[DataProvider('invalidOrderPayloads')]
    public function testInvalidOrderPayloadIsUnrecoverableAndNeverReachesTheErp(array $payload, string $expectedErrors): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();

        $entry = new WebhookInboxEntry('wh-invalid-payload', 'orders/create', json_encode($payload, \JSON_THROW_ON_ERROR));
        $entityManager->persist($entry);
        $entityManager->flush();

        $erpClient = new MockHttpClient(new MockResponse('', ['http_code' => 201]), 'http://erp.test');
        $shopifyHttp = new MockHttpClient(new MockResponse(''));
        $handler = $this->handlerWith($erpClient, $shopifyHttp);

        $thrown = null;
        try {
            $handler(new ProcessWebhook($entry->getId()));
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // Unrecoverable → Messenger skips the retries and moves the message to the failure transport.
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $thrown);
        self::assertStringContainsString($expectedErrors, $thrown->getMessage());
        // The validation runs before any outbound call ...
        self::assertSame(0, $erpClient->getRequestsCount());
        self::assertSame(0, $shopifyHttp->getRequestsCount());
        // ... and the rollback leaves the entry unprocessed.
        self::assertNull($connection->fetchOne('SELECT processed_at FROM webhook_inbox WHERE id = ?', [$entry->getId()]));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('TRUNCATE TABLE webhook_inbox');

        parent::tearDown();
    }
}
