<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'webhook_inbox')]
#[ORM\UniqueConstraint(name: 'uniq_webhook_id', columns: ['webhook_id'])]
class WebhookInboxEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $webhookId;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $topic;

    #[ORM\Column(type: Types::TEXT)]
    private string $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    public function __construct(string $webhookId, string $topic, string $payload)
    {
        $this->webhookId = $webhookId;
        $this->topic = $topic;
        $this->payload = $payload;
        $this->receivedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWebhookId(): string
    {
        return $this->webhookId;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getReceivedAt(): DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function isProcessed(): bool
    {
        return $this->processedAt !== null;
    }

    public function markProcessed(): void
    {
        $this->processedAt = new DateTimeImmutable();
    }
}
