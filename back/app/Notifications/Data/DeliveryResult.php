<?php

namespace App\Notifications\Data;

use App\Notifications\Enums\DeliveryStatus;

final readonly class DeliveryResult
{
    private function __construct(
        public DeliveryStatus $status,
        public ?string $providerMessageId = null,
        public ?string $failureReason = null,
    ) {}

    public static function sent(string $providerMessageId): self
    {
        return new self(DeliveryStatus::Sent, providerMessageId: $providerMessageId);
    }

    public static function failed(string $reason): self
    {
        return new self(DeliveryStatus::Failed, failureReason: $reason);
    }

    public static function suppressed(SuppressionReason $reason): self
    {
        return new self(DeliveryStatus::Suppressed, failureReason: $reason->value);
    }
}
