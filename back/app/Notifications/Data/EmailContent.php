<?php

namespace App\Notifications\Data;

final readonly class EmailContent implements MessageContent
{
    public function __construct(
        public string $subject,
        public string $body,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {}
}
