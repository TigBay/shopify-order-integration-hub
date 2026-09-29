<?php

declare(strict_types=1);

namespace App\Shopify;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final readonly class HmacVerifier
{
    public function __construct(
        #[Autowire(env: 'SHOPIFY_CLIENT_SECRET')] private string $clientSecret,
    ) {
    }

    public function isValid(Request $request): bool
    {
        $raw = $request->getContent();
        $header = (string) $request->headers->get('X-Shopify-Hmac-Sha256');

        if ('' === $raw || '' === $header) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $raw, $this->clientSecret, true));

        return hash_equals($expected, $header);
    }
}
