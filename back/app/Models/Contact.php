<?php

namespace App\Models;

use App\Enums\ContactType;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Contact extends Model
{
    use HasGuid;

    protected $fillable = [
        'guid', 'contactable_type', 'contactable_id',
        'type', 'label', 'value', 'is_primary', 'use_for_alerts',
    ];

    protected function casts(): array
    {
        return [
            'type'           => ContactType::class,
            'is_primary'     => 'boolean',
            'use_for_alerts' => 'boolean',
        ];
    }

    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Scope: contactos marcados para envío de alertas. */
    public function scopeForAlerts(Builder $query): Builder
    {
        return $query->where('use_for_alerts', true);
    }
}
