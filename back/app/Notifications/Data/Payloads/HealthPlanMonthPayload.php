<?php

namespace App\Notifications\Data\Payloads;

use Spatie\LaravelData\Data;

final class HealthPlanMonthPayload extends Data
{
    public function __construct(
        public int $month,
    ) {}
}
