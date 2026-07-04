<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasGuid;

    protected $fillable = ['guid', 'name', 'iso_code', 'phone_prefix'];

    public function documentTypes(): HasMany
    {
        return $this->hasMany(DocumentType::class);
    }

    public function vets(): HasMany
    {
        return $this->hasMany(Vet::class);
    }
}
