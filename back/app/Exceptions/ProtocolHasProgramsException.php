<?php

namespace App\Exceptions;

class ProtocolHasProgramsException extends \RuntimeException
{
    public function __construct(private readonly int $count)
    {
        parent::__construct('El protocolo tiene programas vinculados y no puede eliminarse.');
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
