<?php

namespace App\Exceptions;

class EstablishmentHealthPlanAlreadyExistsException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ya existe un plan activo para este establecimiento, plantilla y año.');
    }
}
