<?php

namespace App\Notifications\Models;

use App\Enums\ContactType;
use App\Models\PushSubscription;
use App\Models\UserProfile;
use App\Notifications\Data\Recipient;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Exceptions\RecipientContactNotFoundException;
use App\Notifications\Support\PhoneNumber;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertRecipient extends Model
{
    use HasGuid;

    protected $fillable = [
        'alert_id', 'user_profile_id', 'channel', 'status', 'provider_message_id',
        'attempts', 'failure_reason', 'sent_at', 'delivered_at', 'confirmed_at', 'idempotency_key',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class);
    }

    /** @throws RecipientContactNotFoundException si el perfil no tiene un contacto habilitado para este canal */
    public function toDto(): Recipient
    {
        if ($this->channel === Channel::Push) {
            return $this->toPushDto();
        }

        $contactType = match ($this->channel) {
            Channel::Whatsapp => ContactType::Whatsapp,
            Channel::Sms => ContactType::Phone,
            Channel::Email => ContactType::Email,
            default => throw new RecipientContactNotFoundException(
                "No hay resolución de contacto implementada para el canal {$this->channel->value}",
            ),
        };

        $contact = $this->userProfile->contacts()
            ->forAlerts()
            ->where('type', $contactType)
            ->first();

        if ($contact === null) {
            throw new RecipientContactNotFoundException(
                "El perfil {$this->userProfile->guid} no tiene un contacto {$contactType->value} habilitado para alertas",
            );
        }

        return new Recipient(
            userId: $this->userProfile->user_id,
            phone: $this->channel === Channel::Email ? null : PhoneNumber::normalize($contact->value),
            name: $this->userProfile->user->name,
            channel: $this->channel,
            email: $this->channel === Channel::Email ? $contact->value : null,
        );
    }

    /**
     * Ya no es el mecanismo principal de filtrado del canal Push (ese rol lo cumple
     * AlertRecipientFactory::hasActiveSubscription() antes de crear la fila) — queda como
     * red de seguridad secundaria para el caso borde de que el usuario revoque el permiso
     * push entre el momento en que se creó el AlertRecipient y el momento real del despacho.
     *
     * @throws RecipientContactNotFoundException si el perfil no tiene ninguna suscripción push activa
     */
    private function toPushDto(): Recipient
    {
        $hasActiveSubscription = PushSubscription::where('user_id', $this->userProfile->user_id)->exists();

        if (! $hasActiveSubscription) {
            throw new RecipientContactNotFoundException(
                "El perfil {$this->userProfile->guid} no tiene ninguna suscripción push activa",
            );
        }

        return new Recipient(
            userId: $this->userProfile->user_id,
            phone: null,
            name: $this->userProfile->user->name,
            channel: Channel::Push,
        );
    }
}
