<?php

declare(strict_types=1);

namespace App\Controller;

use App\Shopify\HmacVerifier;
use App\Shopify\WebhookInbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShopifyWebhookController extends AbstractController
{
    #[Route('/webhooks/shopify', name: 'webhook_shopify', methods: ['POST'])]
    public function __invoke(
        Request      $request,
        HmacVerifier $hmacVerifier,
        WebhookInbox $inbox,
    ): Response
    {
        if (!$hmacVerifier->isValid($request)) {
            return new Response(status: Response::HTTP_UNAUTHORIZED);
        }

        $webhookId = (string)$request->headers->get('X-Shopify-Webhook-Id');
        $topic = (string)$request->headers->get('X-Shopify-Topic');

        if ($webhookId === '' || $topic === '') {
            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        if (!$inbox->receive($webhookId, $topic, $request->getContent())) {
            return new Response(status: Response::HTTP_OK);
        }

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
