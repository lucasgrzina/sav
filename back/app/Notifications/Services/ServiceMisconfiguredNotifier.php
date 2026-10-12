<?php

namespace App\Notifications\Services;

use App\Mail\AlertMail;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Operational alert for failures that need an operator, not the tenant (invalid
 * credentials, missing template, unknown provider). Always logged as critical; e-mailed to
 * NOTIFICATIONS_OPS_EMAIL at most once per hour so a misconfiguration affecting hundreds
 * of recipients does not flood the inbox. No new infrastructure.
 */
final class ServiceMisconfiguredNotifier
{
    private const THROTTLE_KEY = 'notif:misconfigured';
    private const THROTTLE_SECONDS = 3600;

    public function report(AlertRecipient $recipient, Throwable $e): void
    {
        Log::critical('Notificaciones: servicio de envío mal configurado', [
            'alert_id' => $recipient->alert_id,
            'recipient_id' => $recipient->id,
            'channel' => $recipient->channel->value,
            'error' => $e->getMessage(),
        ]);

        $to = config('notifications.ops_email');

        if (! is_string($to) || $to === '') {
            return;
        }

        if (! Cache::add(self::THROTTLE_KEY, 1, self::THROTTLE_SECONDS)) {
            return;
        }

        try {
            Mail::to($to)->send(new AlertMail(
                '[SAV] Servicio de notificaciones mal configurado',
                "El servicio de notificaciones de SAV está mal configurado.\n\n"
                . "Canal: {$recipient->channel->value}\n"
                . "Error: {$e->getMessage()}\n\n"
                . 'Este aviso se envía como máximo una vez por hora.',
            ));
        } catch (Throwable $mailError) {
            // The operational alert must never break the delivery pipeline.
            Log::error('ServiceMisconfiguredNotifier: no se pudo enviar el correo operativo', [
                'error' => $mailError->getMessage(),
            ]);
        }
    }
}
