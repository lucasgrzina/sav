<?php

namespace App\Notifications\Contracts;

use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Enums\Channel;

interface NotificationChannelGateway
{
    public function channel(): Channel;

    /**
     * Sends the message and returns a normalized result.
     * Must not throw for business-level failures (invalid number, rejection) — those
     * travel in DeliveryResult. Only throw on transient failures (timeout, 5xx) so the
     * queue applies backoff.
     */
    public function send(OutboundMessage $message): DeliveryResult;
}
