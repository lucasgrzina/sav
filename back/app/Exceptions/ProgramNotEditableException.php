<?php

namespace App\Exceptions;

class ProgramNotEditableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('El programa está cancelado y no puede editarse.');
    }
}
