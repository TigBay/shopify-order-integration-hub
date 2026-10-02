<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookInboxEntry;
use App\Erp\ErpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'orders/create')]
final readonly class OrdersCreateHandler implements WebhookTopicHandler
{
    public function __construct(private readonly ErpClient $erpClient, private readonly LoggerInterface $logger)
    {
    }

    public function handle(WebhookInboxEntry $entry): void
    {
        $payload = json_decode($entry->getPayload(), true, flags: \JSON_THROW_ON_ERROR);

        $result = $this->erpClient->createOrder($entry->getWebhookId(), [
            'shopifyOrderId' => $payload['id'],
            'orderName' => $payload['name'],
            'currency' => $payload['currency'],
            'lines' => array_map(
                static fn (array $line): array => ['sku' => $line['sku'], 'quantity' => $line['quantity']],
                $payload['line_items'],
            ),
        ]);

        $this->logger->info('Order sent to ERP', [
            'shopify_order_id' => $payload['id'],
            'erp_order_number' => $result->erpOrderNumber,
        ]);
    }
}
