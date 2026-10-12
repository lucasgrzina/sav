<?php

namespace App\Notifications\Data;

use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;

final readonly class DeliveryResult
{
    private function __construct(
        public DeliveryStatus $status,
        public ?string $providerMessageId = null,
        public ?string $failureReason = null,
        public ?FailureCode $failureCode = null,
    ) {}

    public static function sent(string $providerMessageId): self
    {
        return new self(DeliveryStatus::Sent, providerMessageId: $providerMessageId);
    }

    public static function failed(string $reason, ?FailureCode $code = null): self
    {
        return new self(DeliveryStatus::Failed, failureReason: $reason, failureCode: $code);
    }

    public static function suppressed(SuppressionReason $reason): self
    {
        return new self(
            DeliveryStatus::Suppressed,
            failureReason: $reason->value,
            failureCode: FailureCode::tryFrom($reason->value),
        );
    }
}
