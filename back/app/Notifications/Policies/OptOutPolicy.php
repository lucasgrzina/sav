<?php

namespace App\Notifications\Policies;

use App\Notifications\Contracts\DeliveryPolicy;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\SuppressionReason;
use App\Notifications\Models\OptOut;

final class OptOutPolicy implements DeliveryPolicy
{
    public function check(OutboundMessage $message): ?SuppressionReason
    {
        $optedOut = OptOut::query()
            ->where('phone', $message->recipient->phone)
            ->where('channel', $message->channel->value)
            ->exists();

        return $optedOut ? SuppressionReason::OptedOut : null;
    }
}
