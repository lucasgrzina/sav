<?php

namespace App\Notifications\Services;

use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Escalates a definitively failed recipient to the next configured channel.
 *
 * This lives outside DeliverAlertJob because a delivery can fail in two distinct moments:
 * synchronously, when the gateway rejects the send or the queue exhausts its retries; and
 * asynchronously, when the provider accepts the message (so the recipient is already Sent)
 * and only reports the rejection later through a delivery-status webhook. Both paths must
 * be able to escalate, and the webhook handler cannot reach into the job to do it.
 */
final class ChannelFallbackService
{
    /** Dispatches the first configured fallback channel not yet attempted for this alert+profile. */
    public function attempt(AlertRecipient $recipient): void
    {
        $fallbackChannels = config('notifications.fallback')[$recipient->channel->value] ?? [];

        foreach ($fallbackChannels as $fallbackChannelValue) {
            $fallbackChannel = Channel::from($fallbackChannelValue);

            $alreadyAttempted = AlertRecipient::query()
                ->where('alert_id', $recipient->alert_id)
                ->where('user_profile_id', $recipient->user_profile_id)
                ->where('channel', $fallbackChannel)
                ->exists();

            if ($alreadyAttempted) {
                continue;
            }

            $fallbackRecipient = AlertRecipient::create([
                'alert_id' => $recipient->alert_id,
                'user_profile_id' => $recipient->user_profile_id,
                'channel' => $fallbackChannel,
                'status' => DeliveryStatus::Pending,
                'idempotency_key' => Str::uuid()->toString(),
            ]);

            Log::warning('ChannelFallbackService: entregando alerta por un canal degradado', [
                'alert_id' => $recipient->alert_id,
                'recipient_id' => $recipient->id,
                'channel' => $fallbackChannel->value,
            ]);

            DeliverAlertJob::dispatch($fallbackRecipient->id);

            return;
        }

        // Sin canal de fallback configurado, o todos los ya configurados fueron intentados
        // para este alert+perfil: la alerta queda sin entregar y sin ningún reintento futuro.
        Log::error('ChannelFallbackService: sin canal de fallback disponible, la alerta queda sin entregar', [
            'alert_id' => $recipient->alert_id,
            'recipient_id' => $recipient->id,
            'channel' => $recipient->channel->value,
        ]);
    }
}
