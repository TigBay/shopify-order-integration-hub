<?php

declare(strict_types=1);

namespace App\Shopify;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GraphqlClient
{
    private const string API_VERSION = '2026-10'; // see shopify.app.toml

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly TokenProvider $tokenProvider,
        #[Autowire(env: 'SHOPIFY_SHOP')] private readonly string $shop,
    ) {
    }

    public function query(string $query, array $variables = []): array
    {
        // prevent error -> graphql requires an empty json
        $variables = $variables ?: new \stdClass();

        $response = $this->client->request('POST', sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $this->shop,
            self::API_VERSION,
        ), [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => $this->tokenProvider->getToken(),
            ],
            'json' => ['query' => $query, 'variables' => $variables],
        ]);

        $data = $response->toArray();

        if (isset($data['errors'])) {
            throw new \RuntimeException('GraphQL error: '.json_encode($data['errors']));
        }

        return $data['data'];
    }
}
