<?php

namespace App\Exceptions;

class EstablishmentHealthPlanNotEditableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('El plan sanitario está cancelado y no puede editarse.');
    }
}
