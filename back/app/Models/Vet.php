<?php

namespace App\Models;

use App\Traits\HasContacts;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vet extends Model
{
    use HasGuid, HasContacts;

    protected $fillable = [
        'guid', 'name', 'slug', 'country_id', 'document_type_id',
        'tax_id', 'registration_number', 'validated_at', 'validated_by',
        'suspended_at', 'logo_path', 'pdf_title', 'pdf_subtitle',
    ];

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function userProfiles(): MorphMany
    {
        return $this->morphMany(UserProfile::class, 'authenticatable');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_vet')->withTimestamps();
    }

    /** Scope: tenant activo (validado y no suspendido). */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('validated_at')
            ->whereNull('suspended_at');
    }
}
