<?php

namespace App\Tests\Unit;

use App\Erp\ErpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class ErpClientTest extends TestCase
{
    private const array ORDER = ['shopifyOrderId' => 1001, 'orderName' => '#1001', 'currency' => 'EUR', 'lines' => []];

    public function testCreateOrderReturnsErpOrderNumberAndShipDate(): void
    {
        $client = $this->clientFor(new MockResponse(
            '{"erpOrderNumber":"ERP-1","expectedShipDate":"2026-10-06"}',
            ['http_code' => 201],
        ));

        $result = $client->createOrder('wh-1', self::ORDER);

        self::assertSame('ERP-1', $result->erpOrderNumber);
        self::assertSame('2026-10-06', $result->expectedShipDate->format('Y-m-d'));
    }

    public function testCreateOrderSendsIdempotencyKey(): void
    {
        $response = new MockResponse(
            '{"erpOrderNumber":"ERP-1","expectedShipDate":"2026-10-06"}',
            ['http_code' => 201],
        );

        $this->clientFor($response)->createOrder('wh-1', self::ORDER);

        self::assertContains('Idempotency-Key: wh-1', $response->getRequestOptions()['headers']);
    }

    public function testServerErrorIsNotSwallowedSoMessengerRetries(): void
    {
        $client = $this->clientFor(new MockResponse('', ['http_code' => 503]));

        $this->expectException(ServerExceptionInterface::class);
        $client->createOrder('wh-1', self::ORDER);
    }

    public function testNetworkError(): void
    {
        $client = $this->clientFor(new MockResponse('', ['error' => 'timeout']));

        $this->expectException(TransportExceptionInterface::class);
        $client->createOrder('wh-1', self::ORDER);
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidResponseIsUnrecoverable(string $body): void
    {
        $client = $this->clientFor(new MockResponse($body, ['http_code' => 201]));

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $client->createOrder('wh-1', self::ORDER);
    }

    public static function invalidResponses(): iterable
    {
        yield 'ship date missing' => ['{"erpOrderNumber":"ERP-1"}'];
        yield 'impossible date' => ['{"erpOrderNumber":"ERP-1","expectedShipDate":"2026-02-31"}'];
        yield 'relative date' => ['{"erpOrderNumber":"ERP-1","expectedShipDate":"tomorrow"}'];
    }

    #[DataProvider('retryableClientErrors')]
    public function testSomeClientErrorsAreRetried(int $status): void
    {
        $client = $this->clientFor(new MockResponse('', ['http_code' => $status]));

        $this->expectException(ClientExceptionInterface::class);
        $client->createOrder('wh-1', self::ORDER);
    }

    public static function retryableClientErrors(): iterable
    {
        yield 'too many requests' => [429];
        yield 'request timeout' => [408];
    }

    #[DataProvider('permanentClientErrors')]
    public function testOtherClientErrorsAreUnrecoverable(int $status): void
    {
        $client = $this->clientFor(new MockResponse('', ['http_code' => $status]));

        $this->expectException(UnrecoverableMessageHandlingException::class);   // kein Retry
        $client->createOrder('wh-1', self::ORDER);
    }

    public static function permanentClientErrors(): iterable
    {
        yield 'bad request' => [400];
        yield 'not found' => [404];
        yield 'unprocessable entity' => [422];
    }

    private function clientFor(MockResponse $response): ErpClient
    {
        return new ErpClient(new MockHttpClient($response, 'http://erp.test'));
    }
}
