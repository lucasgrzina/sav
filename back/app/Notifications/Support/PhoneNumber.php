<?php

namespace App\Notifications\Support;

/**
 * Canonical phone identity for the notifications subsystem: digits only, exactly as
 * Meta/WhatsApp reports it — country code plus subscriber number, INCLUDING Argentina's
 * mobile "9" marker.
 *
 * Do NOT strip the leading '9' after Argentina's '54' country code. A previous design draft
 * proposed doing so on the inbound side (docs/planes/arquitectura-notificaciones.md:693-697).
 * Implementing that turns every Argentine opt-out into a silent, permanent no-op: outbound
 * contacts are normalized WITH the '9' (see AlertRecipient::toDto()), and OptOutPolicy
 * compares with an exact SQL '='.
 */
final class PhoneNumber
{
    public static function normalize(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    private function __construct()
    {
        // Static-only helper.
    }
}
