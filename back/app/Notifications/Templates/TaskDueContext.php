<?php

namespace App\Notifications\Templates;

use Carbon\CarbonInterface;

/**
 * Everything needed to word a program.task_due message, independent of where it comes from
 * (a persisted alert at send time or a projected item in the preview), so both render the
 * same text.
 */
final readonly class TaskDueContext
{
    public function __construct(
        public string $vetName,
        public string $clientName,
        public string $establishmentName,
        public string $protocolName,
        public bool $multiGroup,
        public ?string $targetDate,
        public ?string $taskDescription,
        public ?string $taskDate,
        public ?string $taskTime,
        public ?CarbonInterface $scheduledAtLocal,
        public ?string $freeText,
        public string $timezone,
        public string $locale,
    ) {}
}
