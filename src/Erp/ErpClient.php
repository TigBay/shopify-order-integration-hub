<?php

declare(strict_types=1);

namespace App\Erp;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ErpClient
{
    public function __construct(private HttpClientInterface $erpClient)
    {
    }

    public function createOrder(string $idempotencyKey, array $order): ErpOrderResult
    {
        try {
            $response = $this->erpClient->request(
                'POST',
                '/api/orders',
                [
                    'headers' => ['Idempotency-Key' => $idempotencyKey],
                    'json' => $order,
                ],
            );
            $data = $response->toArray();
        } catch (ClientExceptionInterface $e) {
            $status = $e->getResponse()->getStatusCode();
            if (\in_array($status, [408, 429], true)) {
                throw $e;
            }
            throw new UnrecoverableMessageHandlingException('Create order failed (erp client): '.$e->getMessage(), previous: $e);
        }

        return $this->toResult($data);
    }

    private function toResult(array $data): ErpOrderResult
    {
        $number = $data['erpOrderNumber'] ?? null;
        $date = $data['expectedShipDate'] ?? null;
        $errorMessage = 'Create order failed (erp client): missing or invalid erpOrderNumber/expectedShipDate';

        if (!\is_string($number) || '' === $number || !\is_string($date)) {
            throw new UnrecoverableMessageHandlingException($errorMessage);
        }

        $shipDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $shipDate || false !== \DateTimeImmutable::getLastErrors()) {
            throw new UnrecoverableMessageHandlingException($errorMessage);
        }

        return new ErpOrderResult($number, $shipDate);
    }
}
