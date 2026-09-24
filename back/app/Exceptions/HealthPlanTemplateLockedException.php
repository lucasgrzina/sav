<?php

namespace App\Exceptions;

class HealthPlanTemplateLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('La plantilla ya generó planes sanitarios instanciados y no puede editarse ni eliminarse.');
    }
}
