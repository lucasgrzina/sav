<?php

namespace App\Exceptions;

class EstablishmentStaffMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Uno o más perfiles seleccionados no son personal del cliente de este establecimiento.');
    }
}
