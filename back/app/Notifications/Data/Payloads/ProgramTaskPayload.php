<?php

namespace App\Notifications\Data\Payloads;

use Spatie\LaravelData\Data;

final class ProgramTaskPayload extends Data
{
    public function __construct(
        public string $protocolTaskAlertGuid,
        public string $message,
    ) {}
}
