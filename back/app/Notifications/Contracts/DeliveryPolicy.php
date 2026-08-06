<?php

namespace App\Notifications\Contracts;

use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\SuppressionReason;

interface DeliveryPolicy
{
    /** Null if the message may proceed; a reason if it must be stopped. */
    public function check(OutboundMessage $message): ?SuppressionReason;
}
