<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProcessWebhook
{
    public function __construct(
        public int $webhookInboxEntryId,
    ) {
    }
}
