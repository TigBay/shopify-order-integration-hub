<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShopifyWebhookControllerTest extends WebTestCase
{
    private static function secret(): string
    {
        return $_ENV['SHOPIFY_CLIENT_SECRET'] ?? throw new \RuntimeException('SHOPIFY_CLIENT_SECRET is not set in the test environment.');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('TRUNCATE TABLE webhook_inbox');

        parent::tearDown();
    }

    public function testValidWebhookIsAccepted(): void
    {
        $client = self::createClient();
        $secret = self::secret();

        $body = '{"id":123,"order_number":"#1001"}';
        $hmac = base64_encode(hash_hmac('sha256', $body, (string) $secret, true));

        $client->request(
            'POST',
            '/webhooks/shopify',
            server: [
                'HTTP_X-Shopify-Hmac-Sha256' => $hmac,
                'HTTP_X-Shopify-Webhook-Id' => 'wh-1',
                'HTTP_X-Shopify-Topic' => 'orders/create',
            ],
            content: $body,
        );

        self::assertResponseStatusCodeSame(202);
    }

    public function testDuplicateWebhookIsAckedWithoutReprocessing(): void
    {
        $client = self::createClient();
        $secret = self::secret();

        $body = '{"id":123,"order_number":"#1001"}';
        $hmac = base64_encode(hash_hmac('sha256', $body, (string) $secret, true));
        $headers = [
            'HTTP_X-Shopify-Hmac-Sha256' => $hmac,
            'HTTP_X-Shopify-Webhook-Id' => 'wh-2',
            'HTTP_X-Shopify-Topic' => 'orders/create',
        ];

        $client->request('POST', '/webhooks/shopify', server: $headers, content: $body);
        self::assertResponseStatusCodeSame(202);

        $client->request('POST', '/webhooks/shopify', server: $headers, content: $body);
        self::assertResponseStatusCodeSame(200);
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $client = self::createClient();
        $body = '{"id":123}';

        $client->request(
            'POST',
            '/webhooks/shopify',
            server: [
                'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'wrong-secret', true)),
                'HTTP_X-Shopify-Webhook-Id' => 'wh-3',
                'HTTP_X-Shopify-Topic' => 'orders/create',
            ],
            content: $body,
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testMissingTopicHeaderIsRejected(): void
    {
        $client = self::createClient();
        $secret = self::secret();
        $body = '{"id":123}';
        $hmac = base64_encode(hash_hmac('sha256', $body, (string) $secret, true));

        $client->request(
            'POST',
            '/webhooks/shopify',
            server: [
                'HTTP_X-Shopify-Hmac-Sha256' => $hmac,
                'HTTP_X-Shopify-Webhook-Id' => 'wh-4',
            ],
            content: $body,
        );

        self::assertResponseStatusCodeSame(400);
    }
}
