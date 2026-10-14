<?php

namespace App\Notifications\Models;

use App\Models\Vet;
use App\Notifications\Enums\AlertCancelReason;
use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\AlertType;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Alert extends Model
{
    use HasGuid;

    protected $fillable = [
        'type', 'payload', 'scheduled_at', 'status', 'require_confirmation', 'vet_id',
        'cancelled_at', 'cancel_reason', 'logical_key',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'payload' => 'array',
            'scheduled_at' => 'datetime',
            'require_confirmation' => 'boolean',
            'cancelled_at' => 'datetime',
            'cancel_reason' => AlertCancelReason::class,
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [AlertStatus::Pending->value, AlertStatus::Dispatched->value]);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AlertRecipient::class);
    }

    public function vet(): BelongsTo
    {
        return $this->belongsTo(Vet::class);
    }
}
