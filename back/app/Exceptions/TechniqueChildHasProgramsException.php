<?php

namespace App\Exceptions;

class TechniqueChildHasProgramsException extends \RuntimeException
{
    /**
     * @param  array  $conflicts  Array de ['guid' => string, 'name' => string, 'programs_count' => int]
     */
    public function __construct(
        private readonly array $conflicts,
        string $message = 'Algunos sub-técnicas tienen programas vinculados y no pueden eliminarse.'
    ) {
        parent::__construct($message);
    }

    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}
