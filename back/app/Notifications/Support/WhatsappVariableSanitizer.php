<?php

namespace App\Notifications\Support;

/**
 * Makes free text safe to travel as a WhatsApp template variable: Meta rejects variables with
 * newlines, tabs or runs of spaces, so every variable goes through clean() before sending.
 */
final class WhatsappVariableSanitizer
{
    public static function clean(?string $value, int $max, string $fallback = ''): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}]+/u', ' ', (string) $value);
        $text = trim((string) preg_replace('/ {2,}/u', ' ', $text));

        if ($text === '') {
            return $fallback;
        }

        if (mb_strlen($text) > $max) {
            $text = rtrim(mb_substr($text, 0, max(0, $max - 3))) . '...';
        }

        return $text;
    }
}
