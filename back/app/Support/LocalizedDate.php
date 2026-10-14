<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Locale-explicit date helpers: the server locale is never used. */
final class LocalizedDate
{
    public static function weekday(CarbonInterface $date, string $locale): string
    {
        return CarbonImmutable::instance($date)->locale($locale)->isoFormat('dddd');
    }

    public static function weekdayShort(CarbonInterface $date, string $locale): string
    {
        // Spanish short names come with a trailing dot ("jue."), not wanted in labels.
        return rtrim(CarbonImmutable::instance($date)->locale($locale)->isoFormat('ddd'), '.');
    }

    /** e.g. "jue 24/09/2026 09:00" */
    public static function label(CarbonInterface $date, string $locale): string
    {
        return self::weekdayShort($date, $locale) . ' ' . $date->format('d/m/Y H:i');
    }

    /**
     * Calendar-day distance of the task date relative to the send date, in Spanish
     * (0 "hoy", 1 "mañana", 2 "pasado mañana", >2 "en N días", -1 "ayer", <-1 "hace N días").
     */
    public static function relativeDays(CarbonInterface $taskDate, CarbonInterface $sendDate): string
    {
        $days = intdiv(
            CarbonImmutable::parse($taskDate->toDateString() . ' 00:00:00', 'UTC')->getTimestamp()
                - CarbonImmutable::parse($sendDate->toDateString() . ' 00:00:00', 'UTC')->getTimestamp(),
            86400
        );

        return match (true) {
            $days === 0 => 'hoy',
            $days === 1 => 'mañana',
            $days === 2 => 'pasado mañana',
            $days > 2 => "en {$days} días",
            $days === -1 => 'ayer',
            default => 'hace ' . abs($days) . ' días',
        };
    }
}
