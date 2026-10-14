<?php

namespace App\Services\ProgramAlerts\Data;

enum WarningSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    /** Lower sorts first. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
