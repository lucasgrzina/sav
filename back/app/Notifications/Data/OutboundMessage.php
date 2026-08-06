<?php

namespace App\Notifications\Data;

use App\Notifications\Enums\Channel;

final readonly class OutboundMessage
{
    public function __construct(
        public Recipient $recipient,
        public MessageContent $content,
        public Channel $channel,
        public string $idempotencyKey,
    ) {}
}
