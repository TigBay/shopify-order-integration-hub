<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WebhookInboxEntry;
use App\Shopify\TokenProvider;
use App\Webhook\AppUninstalledHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

final class AppUninstalledHandlerTest extends KernelTestCase
{
    public function testUninstallClearsCachedToken(): void
    {
        self::bootKernel();
        $cache = self::getContainer()->get(CacheInterface::class);
        $cache->delete(TokenProvider::CACHE_KEY);
        $cache->get(TokenProvider::CACHE_KEY, fn () => 'old');

        $handler = self::getContainer()->get(AppUninstalledHandler::class);
        $handler->handle(new WebhookInboxEntry('wh-uninstall-1', 'app/uninstalled', '{}'));

        self::assertSame('new', $cache->get(TokenProvider::CACHE_KEY, fn () => 'new'));
    }
}
