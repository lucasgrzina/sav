<?php

namespace App\Notifications\Services;

use App\Models\PushSubscription;
use App\Models\UserProfile;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AlertRecipientFactory
{
    /**
     * @param iterable<UserProfile> $managers
     * @return Collection<int, AlertRecipient>
     */
    public function createForManagers(Alert $alert, iterable $managers): Collection
    {
        $channels = collect(config('notifications.default_channels', [Channel::Whatsapp]));
        $created = collect();

        foreach ($managers as $manager) {
            foreach ($channels as $channel) {
                // DEC-08: Push solo aplica si el perfil tiene una suscripción activa.
                // "No tiene la app instalada" no es un fallo de entrega — es un canal que
                // no corresponde. Sin este filtro, la mayoría de los destinatarios (son
                // veterinarios de campo resueltos por roles, no usuarios de la PWA)
                // generarían un AlertRecipient Push fallido en cada alerta, para siempre.
                if ($channel === Channel::Push && ! $this->hasActiveSubscription($manager)) {
                    continue;
                }

                $created->push(AlertRecipient::create([
                    'alert_id' => $alert->id,
                    'user_profile_id' => $manager->id,
                    'channel' => $channel,
                    'status' => DeliveryStatus::Pending,
                    'idempotency_key' => Str::uuid()->toString(),
                ]));
            }
        }

        return $created;
    }

    /**
     * Idempotent variant of createForManagers, keyed by (alert, profile, channel): creates the
     * missing rows and reactivates rows that a previous reconciliation suppressed because the
     * profile stopped being responsible (`no_longer_applicable`). Any other existing row is left alone.
     *
     * @param iterable<UserProfile> $managers
     * @return array{created: int, reactivated: int}
     */
    public function ensureForManagers(Alert $alert, iterable $managers): array
    {
        $channels = collect(config('notifications.default_channels', [Channel::Whatsapp]));
        $created = 0;
        $reactivated = 0;

        foreach ($managers as $manager) {
            foreach ($channels as $channel) {
                if ($channel === Channel::Push && ! $this->hasActiveSubscription($manager)) {
                    continue;
                }

                $existing = AlertRecipient::where('alert_id', $alert->id)
                    ->where('user_profile_id', $manager->id)
                    ->where('channel', $channel)
                    ->first();

                if ($existing === null) {
                    AlertRecipient::create([
                        'alert_id' => $alert->id,
                        'user_profile_id' => $manager->id,
                        'channel' => $channel,
                        'status' => DeliveryStatus::Pending,
                        'idempotency_key' => Str::uuid()->toString(),
                    ]);
                    $created++;
                } elseif ($existing->status === DeliveryStatus::Suppressed
                    && $existing->failure_code === FailureCode::NoLongerApplicable) {
                    $existing->update([
                        'status' => DeliveryStatus::Pending,
                        'failure_reason' => null,
                        'failure_code' => null,
                    ]);
                    $reactivated++;
                }
            }
        }

        return ['created' => $created, 'reactivated' => $reactivated];
    }

    private function hasActiveSubscription(UserProfile $manager): bool
    {
        return PushSubscription::where('user_id', $manager->user_id)->exists();
    }
}
