<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shopify\HmacVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HmacVerifierTest extends TestCase
{
    private const string SECRET = 'test-secret';

    public function testValidHmacPasses(): void
    {
        $body = '{"id":123,"order_number":"#1001"}';
        $hmac = base64_encode(hash_hmac('sha256', $body, self::SECRET, true));

        $request = Request::create('/webhooks/shopify', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Shopify-Hmac-Sha256', $hmac);

        $verifier = new HmacVerifier(self::SECRET);
        self::assertTrue($verifier->isValid($request));
    }

    public function testTamperedBodyFails(): void
    {
        $hmac = base64_encode(hash_hmac('sha256', '{"id":123}', self::SECRET, true));

        $request = Request::create('/webhooks/shopify', 'POST', [], [], [], [], '{"id":456}');
        $request->headers->set('X-Shopify-Hmac-Sha256', $hmac);

        $verifier = new HmacVerifier(self::SECRET);
        self::assertFalse($verifier->isValid($request));
    }

    public function testWrongSecretFails(): void
    {
        $body = '{"id":123}';
        $hmac = base64_encode(hash_hmac('sha256', $body, 'anderes-secret', true));

        $request = Request::create('/webhooks/shopify', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Shopify-Hmac-Sha256', $hmac);

        $verifier = new HmacVerifier(self::SECRET);
        self::assertFalse($verifier->isValid($request));
    }

    public function testMissingHeaderFails(): void
    {
        $request = Request::create('/webhooks/shopify', 'POST', [], [], [], [], '{"id":123}');

        $verifier = new HmacVerifier(self::SECRET);
        self::assertFalse($verifier->isValid($request));
    }

    public function testEmptyBodyFails(): void
    {
        $hmac = base64_encode(hash_hmac('sha256', '', self::SECRET, true));

        $request = Request::create('/webhooks/shopify', 'POST', [], [], [], [], '');
        $request->headers->set('X-Shopify-Hmac-Sha256', $hmac);

        $verifier = new HmacVerifier(self::SECRET);
        self::assertFalse($verifier->isValid($request));
    }
}
