<?php

namespace App\Exceptions;

class ProgramMustHaveOneTargetException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('El programa debe tener al menos un objetivo.');
    }
}
