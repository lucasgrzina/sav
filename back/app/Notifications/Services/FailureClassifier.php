<?php

namespace App\Notifications\Services;

use App\Notifications\Enums\Channel;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Exceptions\RecipientContactNotFoundException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Translates raw provider error codes and exceptions into the stable, presentable
 * FailureCode. The mapping lives in config('notifications.failure_map'); anything not
 * mapped becomes Unknown and is logged so the map can be extended.
 */
final class FailureClassifier
{
    public const PROVIDER_META = 'meta';
    public const PROVIDER_TWILIO = 'twilio';

    public function fromProviderCode(string $provider, string|int|null $code): FailureCode
    {
        if ($code !== null && $code !== '') {
            $mapped = config("notifications.failure_map.{$provider}.{$code}");

            if (is_string($mapped) && ($failureCode = FailureCode::tryFrom($mapped)) !== null) {
                return $failureCode;
            }
        }

        Log::warning('FailureClassifier: código de proveedor sin mapear', [
            'provider' => $provider,
            'code' => $code,
        ]);

        return FailureCode::Unknown;
    }

    public function fromThrowable(Throwable $e, ?Channel $channel = null): FailureCode
    {
        if ($e instanceof NotificationConfigurationException) {
            return FailureCode::ServiceMisconfigured;
        }

        if ($e instanceof RecipientContactNotFoundException) {
            return match ($channel) {
                Channel::Whatsapp => FailureCode::NoWhatsappContact,
                Channel::Email => FailureCode::NoEmailContact,
                default => FailureCode::Unknown,
            };
        }

        return FailureCode::ProviderUnreachable;
    }
}
