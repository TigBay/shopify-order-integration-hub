<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookInboxEntry;
use App\Erp\ErpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsTaggedItem(index: 'orders/create')]
final readonly class OrdersCreateHandler implements WebhookTopicHandler
{
    public function __construct(private readonly ErpClient $erpClient, private readonly LoggerInterface $logger)
    {
    }

    public function handle(WebhookInboxEntry $entry): void
    {
        $payload = json_decode($entry->getPayload(), true, flags: \JSON_THROW_ON_ERROR);

        $errors = $this->validate($payload);
        if ([] !== $errors) {
            // Retrying cannot repair a malformed payload, so skip the retries.
            throw new UnrecoverableMessageHandlingException('Invalid orders/create payload: '.implode(', ', $errors));
        }

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

    /**
     * @return list<string> names of the missing or invalid fields, empty if the payload is usable
     */
    private function validate(mixed $payload): array
    {
        if (!\is_array($payload)) {
            return ['payload'];
        }

        $errors = [];
        foreach (['id', 'name', 'currency'] as $field) {
            if (!isset($payload[$field]) || '' === $payload[$field]) {
                $errors[] = $field;
            }
        }

        $lineItems = $payload['line_items'] ?? null;
        if (!\is_array($lineItems) || [] === $lineItems) {
            $errors[] = 'line_items';

            return $errors;
        }

        foreach ($lineItems as $index => $line) {
            foreach (['sku', 'quantity'] as $field) {
                if (!\is_array($line) || !isset($line[$field]) || '' === $line[$field]) {
                    $errors[] = \sprintf('line_items[%s].%s', $index, $field);
                }
            }
        }

        return $errors;
    }
}
