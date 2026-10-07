<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Erp\ErpOrderResult;
use App\Shopify\GraphqlClient;
use App\Shopify\OrderMetafieldWriter;
use App\Shopify\TokenProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class OrderMetafieldWriterTest extends TestCase
{
    public function testWritesOrderNumberAndShipDateAsMetafields(): void
    {
        $response = $this->response([]);

        $this->writerFor($response)->writeErpData(450789469, $this->erpResult());

        $options = $response->getRequestOptions();
        self::assertSame('POST', $response->getRequestMethod());
        self::assertStringContainsString('/graphql.json', $response->getRequestUrl());
        self::assertContains('X-Shopify-Access-Token: test-token', $options['headers']);

        $metafields = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR)['variables']['metafields'];
        self::assertSame([
            [
                'ownerId' => 'gid://shopify/Order/450789469',
                'namespace' => 'erp',
                'key' => 'order_number',
                'type' => 'single_line_text_field',
                'value' => 'ERP-1',
            ],
            [
                'ownerId' => 'gid://shopify/Order/450789469',
                'namespace' => 'erp',
                'key' => 'expected_ship_date',
                'type' => 'date',
                'value' => '2026-10-06',
            ],
        ], $metafields);
    }

    /**
     * @param list<array<string, mixed>> $userErrors
     */
    #[DataProvider('retryableErrors')]
    public function testTransientErrorsAreRetried(array $userErrors): void
    {
        $thrown = $this->thrownBy($this->response($userErrors));

        self::assertInstanceOf(\RuntimeException::class, $thrown);
        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $thrown);
    }

    public static function retryableErrors(): iterable
    {
        yield 'stale object' => [[['field' => ['metafields', '0'], 'message' => 'stale', 'code' => 'STALE_OBJECT']]];
        yield 'internal error' => [[['field' => null, 'message' => 'oops', 'code' => 'INTERNAL_ERROR']]];
        yield 'both transient' => [[
            ['field' => null, 'message' => 'a', 'code' => 'STALE_OBJECT'],
            ['field' => null, 'message' => 'b', 'code' => 'INTERNAL_ERROR'],
        ]];
    }

    /**
     * @param list<array<string, mixed>> $userErrors
     */
    #[DataProvider('permanentErrors')]
    public function testOtherErrorsAreUnrecoverable(array $userErrors): void
    {
        $thrown = $this->thrownBy($this->response($userErrors));

        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $thrown);
    }

    public static function permanentErrors(): iterable
    {
        yield 'invalid value' => [[['field' => ['metafields', '0', 'value'], 'message' => 'bad', 'code' => 'INVALID_VALUE']]];
        yield 'invalid type' => [[['field' => ['metafields', '0', 'type'], 'message' => 'bad', 'code' => 'INVALID_TYPE']]];
        yield 'app not authorized' => [[['field' => null, 'message' => 'no', 'code' => 'APP_NOT_AUTHORIZED']]];
        yield 'unknown code' => [[['field' => null, 'message' => 'new', 'code' => 'SOMETHING_NEW']]];
        yield 'code missing' => [[['field' => null, 'message' => 'no code']]];
        yield 'transient and permanent mixed' => [[
            ['field' => null, 'message' => 'a', 'code' => 'STALE_OBJECT'],
            ['field' => null, 'message' => 'b', 'code' => 'INVALID_VALUE'],
        ]];
    }

    public function testMissingMetafieldsSetIsAnErrorNotASilentSuccess(): void
    {
        $thrown = $this->thrownBy(new MockResponse('{"data":{"metafieldsSet":null}}'));

        self::assertInstanceOf(\RuntimeException::class, $thrown);
        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $thrown);
    }

    private function thrownBy(MockResponse $response): ?\Throwable
    {
        try {
            $this->writerFor($response)->writeErpData(450789469, $this->erpResult());
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function writerFor(MockResponse $response): OrderMetafieldWriter
    {
        // The cached token keeps TokenProvider from making a real request.
        $cache = new ArrayAdapter();
        $cache->get(TokenProvider::CACHE_KEY, static fn () => 'test-token');
        $tokenProvider = new TokenProvider(new MockHttpClient(), $cache, 'test.myshopify.com', 'id', 'secret');

        return new OrderMetafieldWriter(new GraphqlClient(new MockHttpClient($response), $tokenProvider, 'test.myshopify.com'));
    }

    /**
     * @param list<array<string, mixed>> $userErrors
     */
    private function response(array $userErrors): MockResponse
    {
        return new MockResponse(json_encode(
            ['data' => ['metafieldsSet' => ['metafields' => [], 'userErrors' => $userErrors]]],
            \JSON_THROW_ON_ERROR,
        ));
    }

    private function erpResult(): ErpOrderResult
    {
        return new ErpOrderResult('ERP-1', new \DateTimeImmutable('2026-10-06'));
    }
}
