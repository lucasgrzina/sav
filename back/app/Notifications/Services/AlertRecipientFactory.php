<?php

namespace App\Notifications\Services;

use App\Models\PushSubscription;
use App\Models\UserProfile;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
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

    private function hasActiveSubscription(UserProfile $manager): bool
    {
        return PushSubscription::where('user_id', $manager->user_id)->exists();
    }
}
