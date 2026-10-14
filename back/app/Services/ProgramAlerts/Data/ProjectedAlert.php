<?php

namespace App\Services\ProgramAlerts\Data;

use Carbon\CarbonImmutable;

final readonly class ProjectedAlert
{
    /**
     * @param list<string> $roles
     * @param list<ProjectedRecipient> $recipients
     */
    public function __construct(
        public string $targetRef,
        public ?string $targetGuid,
        public string $targetDate,
        public ?string $protocolGuid,
        public string $protocolTaskGuid,
        public string $taskDescription,
        public string $taskDate,
        public string $taskTime,
        public bool $important,
        public string $protocolTaskAlertGuid,
        public string $alertTime,
        public ?string $message,
        public bool $requireConfirmation,
        public array $roles,
        public LocalDateTime $scheduledAtLocal,
        public CarbonImmutable $scheduledAtUtc,
        public bool $isPast,
        public bool $multiGroup,
        public array $recipients,
        public ?string $logicalKey,
    ) {}

    /** Payload v2 (snapshot needed to render the message and to recompute the logical key). */
    public function toPayload(): array
    {
        return [
            'protocolTaskAlertGuid' => $this->protocolTaskAlertGuid,
            'message' => $this->message,
            'programTargetGuid' => $this->targetGuid,
            'protocolTaskGuid' => $this->protocolTaskGuid,
            'protocolGuid' => $this->protocolGuid,
            'targetDate' => $this->targetDate,
            'taskDescription' => $this->taskDescription,
            'taskDate' => $this->taskDate,
            'taskTime' => $this->taskTime,
            'multiGroup' => $this->multiGroup,
            'contextVersion' => 2,
        ];
    }
}
