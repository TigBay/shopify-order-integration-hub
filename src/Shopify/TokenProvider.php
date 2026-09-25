<?php

declare(strict_types=1);

namespace App\Shopify;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TokenProvider
{
    public function __construct(
        private HttpClientInterface $client,
        private CacheInterface      $cache,
        #[Autowire(env: 'SHOPIFY_SHOP')] private readonly string $shop,
        #[Autowire(env: 'SHOPIFY_CLIENT_ID')] private readonly string $clientId,
        #[Autowire(env: 'SHOPIFY_CLIENT_SECRET')] private readonly string $clientSecret,
    ) {
    }

    public function getToken(): string
    {
        return $this->cache->get('shopify_access_token', function (ItemInterface $item): string {
            $response = $this->client->request('POST', "https://{$this->shop}/admin/oauth/access_token", [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            $data = $response->toArray();

            // TTL 23h instead of 24h check
            $item->expiresAfter(23 * 3600);

            return $data['access_token'];
        });
    }
}
