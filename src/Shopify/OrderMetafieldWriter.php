<?php

declare(strict_types=1);

namespace App\Shopify;

use App\Erp\ErpOrderResult;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final readonly class OrderMetafieldWriter
{
    private const string MUTATION = <<<'GRAPHQL'
        mutation SetErpData($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { namespace key }
            userErrors { field message code }
          }
        }
    GRAPHQL;

    /** Codes from MetafieldsSetUserErrorCode that can succeed on a later attempt. */
    private const array RETRYABLE_ERROR_CODES = ['STALE_OBJECT', 'INTERNAL_ERROR'];

    public function __construct(private GraphqlClient $graphqlClient)
    {
    }

    public function writeErpData(int|string $shopifyOrderId, ErpOrderResult $result): void
    {
        $gid = 'gid://shopify/Order/'.$shopifyOrderId;

        $metafields = [
            [
                'ownerId' => $gid,
                'namespace' => 'erp',
                'key' => 'order_number',
                'type' => 'single_line_text_field',
                'value' => $result->erpOrderNumber,
            ],
            [
                'ownerId' => $gid,
                'namespace' => 'erp',
                'key' => 'expected_ship_date',
                'type' => 'date',
                'value' => $result->expectedShipDate->format('Y-m-d'),
            ],
        ];

        $data = $this->graphqlClient->query(self::MUTATION, ['metafields' => $metafields]);

        if (!isset($data['metafieldsSet'])) {
            throw new \RuntimeException('metafieldsSet not found: '.json_encode($data));
        }

        $userErrors = $data['metafieldsSet']['userErrors'] ?? [];
        if ([] === $userErrors) {
            return;
        }

        // Retry only if every error is transient; a single permanent error would fail again anyway.
        if ($this->allRetryable($userErrors)) {
            throw new \RuntimeException('metafieldsSet failed: '.json_encode($userErrors));
        }

        throw new UnrecoverableMessageHandlingException('metafieldsSet failed with unrecoverable errors: '.json_encode($userErrors));
    }

    /**
     * @param array<mixed> $userErrors
     */
    private function allRetryable(array $userErrors): bool
    {
        foreach ($userErrors as $error) {
            $code = \is_array($error) ? ($error['code'] ?? null) : null;
            if (!\in_array($code, self::RETRYABLE_ERROR_CODES, true)) {
                return false;
            }
        }

        return true;
    }
}
