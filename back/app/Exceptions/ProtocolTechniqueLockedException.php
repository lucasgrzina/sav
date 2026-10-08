<?php

namespace App\Exceptions;

class ProtocolTechniqueLockedException extends \RuntimeException
{
    public function __construct(private readonly int $count)
    {
        parent::__construct('La programa no puede modificarse: el protocolo tiene programas vinculados.');
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
