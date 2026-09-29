<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookInboxEntry;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'orders/create')]
final readonly class OrdersCreateHandler implements WebhookTopicHandler
{
    public function handle(WebhookInboxEntry $entry): void
    {
        // TODO P1-A: ERP enrichment + metafieldsSet write-back.
    }
}
