<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\ProcessWebhook;
use App\Shopify\HmacVerifier;
use App\Shopify\WebhookInbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ShopifyWebhookController extends AbstractController
{
    #[Route('/webhooks/shopify', name: 'webhook_shopify', methods: ['POST'])]
    public function __invoke(
        Request $request,
        HmacVerifier $hmacVerifier,
        WebhookInbox $inbox,
        MessageBusInterface $messageBus,
    ): Response {
        if (!$hmacVerifier->isValid($request)) {
            return new Response(status: Response::HTTP_UNAUTHORIZED);
        }

        $webhookId = (string) $request->headers->get('X-Shopify-Webhook-Id');
        $topic = (string) $request->headers->get('X-Shopify-Topic');

        if ($webhookId === '' || $topic === '') {
            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        $entry = $inbox->record($webhookId, $topic, $request->getContent());

        if ($entry === null) {
            // Already recorded this delivery — ack so Shopify stops retrying.
            return new Response(status: Response::HTTP_OK);
        }

        $messageBus->dispatch(new ProcessWebhook($entry->getId() ?? throw new \LogicException('Entry ID must be set after flush.')));

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
