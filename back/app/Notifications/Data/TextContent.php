<?php

namespace App\Notifications\Data;

final readonly class TextContent implements MessageContent
{
    public function __construct(public string $body) {}
}
