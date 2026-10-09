<?php

namespace App\Exceptions;

class EstablishmentHealthPlanActivityConfirmationNotAllowedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tu rol no está habilitado para confirmar esta actividad.');
    }
}
