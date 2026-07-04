<?php

namespace App\Exceptions;

class RoleImmutableException extends \RuntimeException
{
    public function __construct(string $message = 'Los roles de tenant no pueden ser modificados de esta manera.')
    {
        parent::__construct($message);
    }
}
