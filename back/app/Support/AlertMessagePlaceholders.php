<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Pure resolver for the bracketed placeholders authors type in protocol alert messages
 * (e.g. "[establecimiento]", "[fecha del día 0]"). Only the known placeholders are replaced,
 * and only when their value is available: anything else is left exactly as written.
 *
 * "Day 0" is the program target date (the technique's "target date": insemination date, etc.).
 *
 * "[grupo]" is the program group (ProgramTarget). It resolves to the group name when set;
 * otherwise to a descriptive fallback built from the target date and animal count, worded to
 * follow "el grupo": "el grupo [grupo] de X" -> "el grupo del 24/09/2026 (45 animales) de X".
 */
final class AlertMessagePlaceholders
{
    public const ESTABLISHMENT = '[establecimiento]';
    public const GROUP = '[grupo]';
    public const DAY_0 = '[fecha del día 0]';
    public const DAY_PLUS_1 = '[fecha del día +1]';

    public static function resolve(
        ?string $message,
        ?string $establishmentName,
        ?string $day0,
        string $locale = 'es',
        ?string $groupName = null,
        ?int $groupAnimalCount = null,
    ): ?string {
        if ($message === null || ! str_contains($message, '[')) {
            return $message;
        }

        $replacements = [];

        $establishment = $establishmentName !== null ? trim($establishmentName) : '';
        if ($establishment !== '') {
            $replacements[self::ESTABLISHMENT] = $establishment;
        }

        $group = $groupName !== null ? trim($groupName) : '';
        if ($group !== '') {
            $replacements[self::GROUP] = $group;
        }

        if ($day0 !== null && $day0 !== '') {
            $date = CarbonImmutable::parse($day0, 'UTC');
            $replacements[self::DAY_0] = self::format($date, $locale);
            $replacements[self::DAY_PLUS_1] = self::format($date->addDay(), $locale);

            // The fallback needs a real group (known animal count): without one (e.g. a protocol
            // simulation) "[grupo]" stays as written.
            if ($group === '' && $groupAnimalCount !== null) {
                $replacements[self::GROUP] = self::groupFallback($date, $groupAnimalCount);
            }
        }

        return strtr($message, $replacements);
    }

    /** e.g. "del 24/09/2026 (45 animales)" or "del 24/09/2026 (1 animal)". */
    private static function groupFallback(CarbonImmutable $date, int $animalCount): string
    {
        return 'del ' . $date->format('d/m/Y') . ' (' . $animalCount . ($animalCount === 1 ? ' animal)' : ' animales)');
    }

    /** e.g. "jueves 24/09/2026", same shape as the "when" line of the task-due message. */
    private static function format(CarbonImmutable $date, string $locale): string
    {
        return LocalizedDate::weekday($date, $locale) . ' ' . $date->format('d/m/Y');
    }
}
