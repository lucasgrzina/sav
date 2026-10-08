<?php

namespace App\Exceptions;

class ProgramManagerNotLinkedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Uno o más responsables del cliente no están vinculados al establecimiento del programa.');
    }
}
