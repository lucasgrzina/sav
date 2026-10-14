<?php

namespace App\Notifications\Enums;

/**
 * Stable, presentable failure code. The frontend maps copy by code; the raw
 * `failure_reason` stays as internal detail and is never exposed to tenants.
 */
enum FailureCode: string
{
    case NoWhatsappContact = 'no_whatsapp_contact';
    case NoEmailContact = 'no_email_contact';
    case InvalidNumber = 'invalid_number';
    case OptedOut = 'opted_out';
    case ProviderUnreachable = 'provider_unreachable';
    case ProviderRejected = 'provider_rejected';
    case ServiceMisconfigured = 'service_misconfigured';
    case NoLongerApplicable = 'no_longer_applicable';
    case Duplicate = 'duplicate';
    case RateLimited = 'rate_limited';
    case QuietHours = 'quiet_hours';
    case Unknown = 'unknown';

    /** Whether a manual resend can make sense once the underlying cause is fixed. */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::OptedOut, self::NoLongerApplicable, self::Duplicate => false,
            default => true,
        };
    }
}
