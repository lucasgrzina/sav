<?php

namespace App\Notifications\Gateways\WebPush;

use App\Models\PushSubscription;
use App\Notifications\Contracts\NotificationChannelGateway;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\PushContent;
use App\Notifications\Enums\Channel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use RuntimeException;

final class WebPushGateway implements NotificationChannelGateway
{
    /** Límite real de un push (~4KB de payload total, ver D2 del review de mobile). Se
     *  recorta $body para dejar margen a title/tag/url/data y no superar el límite del
     *  navegador — el detalle completo se ve al abrir la app, no hace falta en la notif. */
    private const BODY_MAX_LENGTH = 500;

    public function __construct(private readonly \Minishlink\WebPush\WebPush $client) {}

    public function channel(): Channel
    {
        return Channel::Push;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $subscriptions = PushSubscription::where('user_id', $message->recipient->userId)->get();

        if ($subscriptions->isEmpty()) {
            return DeliveryResult::failed('sin_suscripciones_activas');
        }

        /** @var PushContent $content */
        $content = $message->content;
        $payload = json_encode([
            'title' => $content->title,
            'body' => Str::limit($content->body, self::BODY_MAX_LENGTH),
            'tag' => $content->tag,
            'url' => $content->url,
            'data' => $content->data,
        ]);

        foreach ($subscriptions as $subscription) {
            $this->client->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->p256dh,
                    'authToken' => $subscription->auth_key,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                $payload,
            );
        }

        // D1 (review de mobile): indexar por endpoint ANTES del flush, usando el accessor
        // directo MessageSentReport::getEndpoint() (verificado contra el código fuente real
        // de minishlink/web-push — existe y evita reconstruir la URI a mano desde
        // getRequest()->getUri()). Si el match fallara en silencio, la rama de 404/410 más
        // abajo nunca borraría la fila y la suscripción muerta volvería a fallar en cada
        // alerta futura, exactamente lo que esa rama busca evitar.
        $subscriptionsByEndpoint = $subscriptions->keyBy('endpoint');

        $sentAny = false;
        $allTransient = true;
        $lastReason = 'push_delivery_failed';

        foreach ($this->client->flush() as $report) {
            $subscription = $subscriptionsByEndpoint->get($report->getEndpoint());

            if ($report->isSuccess()) {
                $sentAny = true;
                $allTransient = false;
                continue;
            }

            $status = $report->getResponse()?->getStatusCode();
            $reason = $report->getReason();
            $lastReason = $reason;

            // 404/410: suscripción caducada del lado del navegador — no hay nada que reintentar,
            // y dejarla viva solo generaría el mismo fallo en cada alerta futura.
            if ($report->isSubscriptionExpired() && $subscription !== null) {
                $subscription->delete();
                $allTransient = false;
                continue;
            }

            // Ver DEC-11: fallo determinístico por par de llaves VAPID, no de infraestructura.
            if ($status === 403 && str_contains((string) $report->getResponse()?->getBody(), 'VapidPkHashMismatch')) {
                Log::critical('WebPushGateway: VAPID key mismatch (VapidPkHashMismatch) — las llaves configuradas en APP_VAPID_* no coinciden con las usadas por el navegador al crear esta suscripción. No es recuperable reintentando; si ocurre en múltiples suscripciones a la vez, revisar si se rotaron las llaves VAPID sin coordinar con mobile.', [
                    'subscription_guid' => $subscription?->guid,
                ]);
                $allTransient = false;
                continue;
            }

            if (! in_array($status, [null, 429], true) && $status < 500) {
                $allTransient = false; // 4xx definitivo (endpoint inválido, payload rechazado, etc.)
            }
        }

        if ($sentAny) {
            return DeliveryResult::sent('push-' . $message->idempotencyKey);
        }

        // Transitorio real (timeout/5xx/429 en TODAS las suscripciones): dejar que el job
        // reintente con backoff, igual que Kapso/Twilio ante errores de infraestructura.
        if ($allTransient) {
            throw new RuntimeException("Fallo transitorio de push para todas las suscripciones: {$lastReason}");
        }

        return DeliveryResult::failed($lastReason);
    }
}
