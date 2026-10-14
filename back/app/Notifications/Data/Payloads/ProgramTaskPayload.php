<?php

namespace App\Notifications\Data\Payloads;

use Spatie\LaravelData\Data;

final class ProgramTaskPayload extends Data
{
    /**
     * The nullable tail is the v2 snapshot written by the reconciler (`contextVersion` = 2).
     * A payload without it is a legacy alert and keeps rendering with the v1 template.
     */
    public function __construct(
        public string $protocolTaskAlertGuid,
        public string $message,
        public ?string $programTargetGuid = null,
        public ?string $protocolTaskGuid = null,
        public ?string $protocolGuid = null,
        public ?string $targetDate = null,
        public ?string $taskDescription = null,
        public ?string $taskDate = null,
        public ?string $taskTime = null,
        public ?bool $multiGroup = null,
        public ?int $contextVersion = null,
    ) {}
}
