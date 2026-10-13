<?php

namespace App\Services\ProgramAlerts\Data;

/** A non-blocking advisory. `context` carries only guids and presentable data (never raw contact data). */
final readonly class Warning
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public WarningCode $code,
        public WarningSeverity $severity,
        public array $context = [],
    ) {}

    /** @return array{code: string, severity: string, context: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity->value,
            'context' => $this->context,
        ];
    }
}
