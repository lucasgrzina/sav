<?php

namespace App\Notifications\Registries;

use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Enums\AlertType;
use InvalidArgumentException;

final class MessageBuilderRegistry
{
    /** @var array<string, AlertMessageBuilder> */
    private array $map;

    /** @param iterable<AlertMessageBuilder> $builders */
    public function __construct(iterable $builders)
    {
        $this->map = collect($builders)->keyBy(fn (AlertMessageBuilder $builder) => $builder->type()->value)->all();
    }

    public function for(AlertType $type): AlertMessageBuilder
    {
        return $this->map[$type->value]
            ?? throw new InvalidArgumentException("Sin builder para el tipo de alerta {$type->value}");
    }
}
