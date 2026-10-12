<?php

namespace App\Services\ProgramAlerts\Data;

/** A protocol task placed on a group, with its projected alerts (possibly none). */
final readonly class ProjectedTask
{
    /**
     * @param string $date Y-m-d
     * @param string $time raw protocol task time, as stored
     * @param list<ProjectedAlert> $alerts
     */
    public function __construct(
        public string $targetRef,
        public ?string $targetGuid,
        public string $protocolTaskGuid,
        public string $description,
        public string $date,
        public string $time,
        public bool $important,
        public array $alerts,
    ) {}
}
