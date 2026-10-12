<?php

namespace App\Services\ProgramAlerts;

use App\Notifications\Enums\AlertType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Stable identity of a scheduled alert: program + type + group + protocol task alert + send
 * instant (UTC). The recipient person is deliberately NOT part of it (lives in alert_recipients).
 */
final class LogicalKey
{
    public static function for(
        string $programGuid,
        AlertType $type,
        string $targetGuid,
        string $taskAlertGuid,
        CarbonInterface $scheduledAtUtc,
    ): string {
        $utc = CarbonImmutable::instance($scheduledAtUtc)->utc()->format('Y-m-d H:i:s');

        return sha1("{$programGuid}|{$type->value}|{$targetGuid}|{$taskAlertGuid}|{$utc}");
    }
}
