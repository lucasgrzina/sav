<?php

namespace App\Services\ProgramAlerts\Data;

/** A program group (target date). `ref` identifies it in drafts; `guid` only when persisted. */
final readonly class TargetInput
{
    /** @param string $date Y-m-d */
    public function __construct(
        public string $ref,
        public ?string $guid,
        public string $date,
    ) {}
}
