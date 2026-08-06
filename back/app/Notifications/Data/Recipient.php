<?php

namespace App\Notifications\Data;

use App\Notifications\Enums\Channel;

final readonly class Recipient
{
    /** @param ?string $phone E.164 without leading '+', normalized. Null for email-only recipients. */
    public function __construct(
        public int $userId,
        public ?string $phone,
        public string $name,
        public Channel $channel,
        public ?string $email = null,
    ) {}
}
