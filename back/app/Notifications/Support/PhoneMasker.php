<?php

namespace App\Notifications\Support;

/**
 * Presentable, non-reversible destination hints. Only the country calling code (when
 * known) and the last 4 digits of a phone ever leave the backend.
 */
final class PhoneMasker
{
    /** "5491122334455" -> "+54 ••• •••• 4455". Unknown calling code: "+••• •••• 4455". */
    public static function mask(string $digits): string
    {
        $digits = PhoneNumber::normalize($digits);
        $code = PhoneValidator::callingCode($digits);
        $last4 = substr($digits, -4);

        return ($code === null ? '+•••' : "+{$code}") . ' ••• •••• ' . $last4;
    }

    /** "maria@dominio.com" -> "m•••@dominio.com" */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '•••';
        }

        return mb_substr($email, 0, 1) . '•••' . substr($email, $at);
    }

    private function __construct()
    {
        // Static-only helper.
    }
}
