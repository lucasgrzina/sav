<?php

namespace App\Notifications\Support;

/**
 * Lightweight plausibility check shared by the preview/warnings and the send-time
 * pre-check, so what is previewed is what happens. No external dependency: digits only,
 * E.164 bounds and, when the country calling code is known (config/phone_rules.php), that
 * country's length range. Never Argentina-only.
 */
final class PhoneValidator
{
    /** @param string $digits Normalized phone (see PhoneNumber::normalize), without '+'. */
    public static function isPlausible(string $digits): bool
    {
        if (preg_match('/^[1-9]\d*$/', $digits) !== 1) {
            return false;
        }

        $length = strlen($digits);

        if ($length < (int) config('phone_rules.e164_min', 8) || $length > (int) config('phone_rules.e164_max', 15)) {
            return false;
        }

        $rule = self::ruleFor($digits);

        return $rule === null || ($length >= $rule['min'] && $length <= $rule['max']);
    }

    /** Longest-prefix match of the country calling code, or null when unknown. */
    public static function callingCode(string $digits): ?string
    {
        $rules = (array) config('phone_rules.by_calling_code', []);

        for ($len = 3; $len >= 1; $len--) {
            $prefix = substr($digits, 0, $len);

            if (strlen($prefix) === $len && isset($rules[$prefix])) {
                return $prefix;
            }
        }

        return null;
    }

    /** @return array{iso: string, min: int, max: int}|null */
    private static function ruleFor(string $digits): ?array
    {
        $code = self::callingCode($digits);

        return $code === null ? null : config("phone_rules.by_calling_code.{$code}");
    }

    private function __construct()
    {
        // Static-only helper.
    }
}
