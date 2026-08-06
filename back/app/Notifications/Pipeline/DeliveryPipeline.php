<?php

namespace App\Notifications\Pipeline;

use App\Notifications\Contracts\DeliveryPolicy;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\SuppressionReason;

final class DeliveryPipeline
{
    /** @param iterable<DeliveryPolicy> $policies */
    public function __construct(private readonly iterable $policies) {}

    public function run(OutboundMessage $message): ?SuppressionReason
    {
        foreach ($this->policies as $policy) {
            if ($reason = $policy->check($message)) {
                return $reason;
            }
        }

        return null;
    }
}
