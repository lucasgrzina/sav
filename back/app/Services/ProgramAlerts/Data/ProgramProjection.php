<?php

namespace App\Services\ProgramAlerts\Data;

final readonly class ProgramProjection
{
    /**
     * @param list<ProjectedAlert> $items alerts ordered by send instant (UTC)
     * @param list<ProjectedTask> $tasks every task x group, in protocol order (alerts nested)
     * @param list<array{protocolTaskGuid: string, description: string}> $tasksWithoutAlerts
     */
    public function __construct(
        public array $items,
        public array $tasks,
        public array $tasksWithoutAlerts,
        public string $timezone,
    ) {}

    /** @return array<string, list<ProjectedAlert>> items grouped by target ref */
    public function byTarget(): array
    {
        $grouped = [];

        foreach ($this->items as $item) {
            $grouped[$item->targetRef][] = $item;
        }

        return $grouped;
    }

    /** @return list<ProjectedTask> */
    public function tasksForTarget(string $targetRef): array
    {
        return array_values(array_filter($this->tasks, fn (ProjectedTask $task) => $task->targetRef === $targetRef));
    }
}
