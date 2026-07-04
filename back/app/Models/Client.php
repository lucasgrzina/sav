<?php

namespace App\Models;

use App\Traits\HasContacts;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasGuid, HasContacts;

    protected $fillable = [
        'guid', 'name', 'country_id', 'document_type_id',
        'tax_id', 'address', 'city', 'state', 'zip_code',
    ];

    protected $hidden = ['id'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function vets(): BelongsToMany
    {
        return $this->belongsToMany(Vet::class, 'client_vet')->withTimestamps();
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }
}
