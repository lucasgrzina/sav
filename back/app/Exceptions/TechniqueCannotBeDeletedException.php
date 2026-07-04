<?php

namespace App\Exceptions;

class TechniqueCannotBeDeletedException extends \RuntimeException
{
    public function __construct(
        private readonly string $reason,
        private readonly int    $count,
        string                  $message = 'La técnica no puede eliminarse.'
    ) {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
