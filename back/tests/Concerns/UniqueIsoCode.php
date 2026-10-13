<?php

namespace Tests\Concerns;

use App\Models\Country;
use Illuminate\Support\Str;

/**
 * countries.iso_code is char(2) UNIQUE. Random two-letter codes collide often enough to make the
 * suite flaky, and longer codes only pass on SQLite (MySQL rejects them in strict mode), so every
 * test that creates a Country must take its code from here.
 */
final class UniqueIsoCode
{
    public static function next(): string
    {
        do {
            $iso = strtoupper(Str::random(2));
        } while (Country::where('iso_code', $iso)->exists());

        return $iso;
    }
}
