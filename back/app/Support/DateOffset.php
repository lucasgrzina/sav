<?php

namespace App\Support;

use Carbon\Carbon;

final class DateOffset
{
    /**
     * Shared by ProgramService::projectTargetTasks (read-only simulation) and the
     * notifications module (real Alert generation) so the two never drift apart.
     */
    public static function apply(Carbon|string $date, int $offsetDays, string $timeOfDay): Carbon
    {
        $base = Carbon::parse($date);

        return $timeOfDay === 'after' ? $base->copy()->addDays($offsetDays) : $base->copy()->subDays($offsetDays);
    }
}
