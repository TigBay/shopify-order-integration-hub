<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookInboxEntry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'app/scopes_update')]
final readonly class AppScopesUpdateHandler implements WebhookTopicHandler
{
    public function __construct(private LoggerInterface $logger)
    {

    }

    public function handle(WebhookInboxEntry $entry): void
    {
        $this->logger->info('App scopes updated', ['webhook_id' => $entry->getWebhookId()]);
    }
}
