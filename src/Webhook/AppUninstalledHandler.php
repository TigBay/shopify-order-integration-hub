<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookInboxEntry;
use App\Shopify\TokenProvider;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'app/uninstalled')]
final readonly class AppUninstalledHandler implements WebhookTopicHandler
{
    public function __construct(private readonly TokenProvider $tokenProvider)
    {
    }

    public function handle(WebhookInboxEntry $entry): void
    {
        $this->tokenProvider->forgetToken();
    }
}
