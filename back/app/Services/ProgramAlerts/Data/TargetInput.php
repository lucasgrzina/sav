<?php

namespace App\Services\ProgramAlerts\Data;

/** A program group (target date). `ref` identifies it in drafts; `guid` only when persisted. */
final readonly class TargetInput
{
    /**
     * @param string $date Y-m-d
     * @param ?string $name optional group name, feeds the [grupo] placeholder
     * @param ?int $animalCount feeds the [grupo] fallback when the group has no name
     */
    public function __construct(
        public string $ref,
        public ?string $guid,
        public string $date,
        public ?string $name = null,
        public ?int $animalCount = null,
    ) {}
}
