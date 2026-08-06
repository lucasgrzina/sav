<?php

namespace App\Exceptions;

class ProtocolTechniqueLockedException extends \RuntimeException
{
    public function __construct(private readonly int $count)
    {
        parent::__construct('La sub-técnica no puede modificarse: el protocolo tiene programas vinculados.');
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
