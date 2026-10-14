<?php

namespace App\Notifications\Registries;

use App\Notifications\Contracts\AlertRevalidator;
use App\Notifications\Enums\AlertType;

final class AlertRevalidatorRegistry
{
    /** @var array<string, AlertRevalidator> */
    private array $map;

    /** @param iterable<AlertRevalidator> $revalidators */
    public function __construct(iterable $revalidators)
    {
        $this->map = collect($revalidators)->keyBy(fn (AlertRevalidator $revalidator) => $revalidator->type()->value)->all();
    }

    /** Types without a revalidator are always considered valid. */
    public function for(AlertType $type): ?AlertRevalidator
    {
        return $this->map[$type->value] ?? null;
    }
}
