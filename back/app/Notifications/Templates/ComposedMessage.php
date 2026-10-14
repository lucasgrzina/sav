<?php

namespace App\Notifications\Templates;

/** A rendered WhatsApp message: which template version, the positional variables, and the final text. */
final readonly class ComposedMessage
{
    /** @param array<int, string> $variables 1-based ordinal variables, as the gateways send them */
    public function __construct(
        public int $version,
        public array $variables,
        public string $text,
    ) {}
}
