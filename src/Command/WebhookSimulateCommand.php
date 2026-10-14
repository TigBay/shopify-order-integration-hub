<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:webhook:simulate', description: 'Sends a signed Shopify webhook to the local receiver')]
final readonly class WebhookSimulateCommand
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'SHOPIFY_CLIENT_SECRET')] private string $clientSecret,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Webhook topic')] string $topic = 'orders/create',
        #[Option(description: 'Order name "#FAIL" makes the WireMock ERP answer with 503')] bool $fail = false,
        #[Option(description: 'Shopify order id (use a real one to see the write-back succeed)')] ?int $orderId = null,
        #[Option(description: 'Receiver URL')] string $url = 'http://127.0.0.1:8000/webhooks/shopify',
    ): int {
        $orderId ??= random_int(1_000_000, 9_999_999);

        $body = json_encode([
            'id' => $orderId,
            'name' => $fail ? '#FAIL' : '#'.random_int(1000, 9999),
            'currency' => 'EUR',
            'line_items' => [['sku' => 'ABC-1', 'quantity' => 2]],
        ], \JSON_THROW_ON_ERROR);

        $webhookId = bin2hex(random_bytes(16));

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'X-Shopify-Topic' => $topic,
                    'X-Shopify-Webhook-Id' => $webhookId,
                    // Signature over the exact bytes sent below, as HmacVerifier expects it.
                    'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, $this->clientSecret, true)),
                ],
                'body' => $body,
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $io->error(\sprintf('Receiver not reachable at %s: %s', $url, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('webhook_id: %s', $webhookId));

        return match ($status) {
            202 => $this->done($io, 'Accepted (202): queued for the worker', Command::SUCCESS),
            200 => $this->done($io, 'Duplicate (200): webhook_id was already received', Command::SUCCESS),
            401 => $this->done($io, 'Rejected (401): HMAC does not match, check SHOPIFY_CLIENT_SECRET', Command::FAILURE),
            default => $this->done($io, \sprintf('Unexpected status %d', $status), Command::FAILURE),
        };
    }

    private function done(SymfonyStyle $io, string $message, int $result): int
    {
        Command::SUCCESS === $result ? $io->success($message) : $io->error($message);

        return $result;
    }
}
