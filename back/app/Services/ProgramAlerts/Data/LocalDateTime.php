<?php

namespace App\Services\ProgramAlerts\Data;

use App\Support\LocalizedDate;
use Carbon\CarbonInterface;

/**
 * Date-time already expressed in the vet's timezone and ready to display, so the frontend
 * never converts zones nor computes weekdays.
 */
final readonly class LocalDateTime
{
    public function __construct(
        public string $iso,
        public string $date,
        public string $time,
        public string $weekday,
        public string $weekdayShort,
        public string $label,
    ) {}

    public static function from(CarbonInterface $local, string $locale): self
    {
        return new self(
            iso: $local->toIso8601String(),
            date: $local->format('Y-m-d'),
            time: $local->format('H:i'),
            weekday: LocalizedDate::weekday($local, $locale),
            weekdayShort: LocalizedDate::weekdayShort($local, $locale),
            label: LocalizedDate::label($local, $locale),
        );
    }

    /** @return array{iso: string, date: string, time: string, weekday: string, weekday_short: string, label: string} */
    public function toArray(): array
    {
        return [
            'iso' => $this->iso,
            'date' => $this->date,
            'time' => $this->time,
            'weekday' => $this->weekday,
            'weekday_short' => $this->weekdayShort,
            'label' => $this->label,
        ];
    }
}
