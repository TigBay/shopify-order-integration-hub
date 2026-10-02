<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookInboxEntry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'customers/data_request')]
final readonly class CustomersDataRequestHandler implements WebhookTopicHandler
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(WebhookInboxEntry $entry): void
    {
        $payload = json_decode($entry->getPayload(), true, flags: \JSON_THROW_ON_ERROR);
        $this->logger->info('Customer data request received', [
            'data_request_id' => $payload['data_request']['id'] ?? null,
            'customer_id' => $payload['customer']['id'] ?? null,
        ]);
    }
}
