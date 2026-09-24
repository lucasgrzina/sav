<?php

namespace App\Support;

use App\Models\Country;
use Carbon\Carbon;

/**
 * Resuelve el rango de fechas del año ganadero y la fecha concreta de una
 * actividad por mes, parametrizado por país (regla dura #3 y #5). Mismo
 * estilo que App\Support\DateOffset.
 */
final class HealthPlanYear
{
    /** iso_code => mes de inicio del año ganadero. Default 1 (enero) para cualquier otro país. */
    private const START_MONTH_BY_ISO = ['AR' => 7];

    public static function startMonth(Country $country): int
    {
        return self::START_MONTH_BY_ISO[$country->iso_code] ?? 1;
    }

    /**
     * @return array{0: Carbon, 1: Carbon} [starts_on, ends_on]
     */
    public static function range(Country $country, int $year): array
    {
        $start = Carbon::create($year, self::startMonth($country), 1)->startOfDay();

        return [$start, $start->copy()->addYear()->subDay()];
    }

    public static function dateForMonth(Country $country, int $year, int $month): Carbon
    {
        $startMonth   = self::startMonth($country);
        $calendarYear = $month >= $startMonth ? $year : $year + 1;

        return Carbon::create($calendarYear, $month, 1)->startOfDay();
    }
}
