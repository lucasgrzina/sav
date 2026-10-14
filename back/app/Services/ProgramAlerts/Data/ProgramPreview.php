<?php

namespace App\Services\ProgramAlerts\Data;

/** Result of previewing a program (create or edit). Nothing in it has been persisted or sent. */
final readonly class ProgramPreview
{
    /**
     * @param array<string, mixed> $program
     * @param array<string, mixed> $summary
     * @param list<array<string, mixed>> $items
     * @param list<Warning> $warnings
     * @param array<string, mixed>|null $diff only when previewing an edit
     */
    public function __construct(
        public string $generatedAt,
        public string $timezone,
        public bool $truncated,
        public int $templateVersion,
        public array $program,
        public array $summary,
        public array $items,
        public array $warnings,
        public ?array $diff,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'timezone' => $this->timezone,
            'truncated' => $this->truncated,
            'template' => ['task_due_version' => $this->templateVersion],
            'program' => $this->program,
            'summary' => $this->summary,
            'items' => $this->items,
            'warnings' => array_map(fn (Warning $w) => $w->toArray(), $this->warnings),
            'diff' => $this->diff,
        ];
    }
}
