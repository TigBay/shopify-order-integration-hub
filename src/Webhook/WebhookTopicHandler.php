<?php

namespace App\Webhook;

use App\Entity\WebhookInboxEntry;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.webhook_topic_handler')]
interface WebhookTopicHandler
{
    public function handle(WebhookInboxEntry $entry): void;
}
