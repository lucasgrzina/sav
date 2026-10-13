<?php

namespace App\Services\ProgramAlerts\Data;

/**
 * Same rules, three audiences. Preview and Save report everything; Tab (the alerts listing)
 * avoids historical noise: ALERT_IN_PAST drops to `info` and recipient/role warnings only
 * count items that can still be sent.
 */
enum WarningMode: string
{
    case Preview = 'preview';
    case Save = 'save';
    case Tab = 'tab';
}
