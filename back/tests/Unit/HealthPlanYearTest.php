<?php

namespace Tests\Unit;

use App\Models\Country;
use App\Support\HealthPlanYear;
use Tests\TestCase;

class HealthPlanYearTest extends TestCase
{
    private function country(string $isoCode): Country
    {
        return new Country(['iso_code' => $isoCode]);
    }

    public function test_start_month_for_ar_is_january(): void
    {
        $this->assertEquals(1, HealthPlanYear::startMonth($this->country('AR')));
    }

    public function test_start_month_for_other_country_defaults_to_january(): void
    {
        $this->assertEquals(1, HealthPlanYear::startMonth($this->country('MX')));
    }

    public function test_range_for_ar_spans_calendar_year(): void
    {
        [$start, $end] = HealthPlanYear::range($this->country('AR'), 2026);

        $this->assertEquals('2026-01-01', $start->toDateString());
        $this->assertEquals('2026-12-31', $end->toDateString());
    }

    public function test_range_for_non_ar_spans_calendar_year(): void
    {
        [$start, $end] = HealthPlanYear::range($this->country('MX'), 2026);

        $this->assertEquals('2026-01-01', $start->toDateString());
        $this->assertEquals('2026-12-31', $end->toDateString());
    }

    public function test_date_for_month_stays_in_plan_year_ar(): void
    {
        $this->assertEquals('2026-01-01', HealthPlanYear::dateForMonth($this->country('AR'), 2026, 1)->toDateString());
        $this->assertEquals('2026-12-01', HealthPlanYear::dateForMonth($this->country('AR'), 2026, 12)->toDateString());
    }

    public function test_date_for_month_non_ar_always_matches_calendar_year(): void
    {
        $date = HealthPlanYear::dateForMonth($this->country('MX'), 2026, 3);

        $this->assertEquals('2026-03-01', $date->toDateString());
    }
}
