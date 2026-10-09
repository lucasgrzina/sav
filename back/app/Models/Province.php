<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    use HasGuid;

    protected $fillable = ['guid', 'country_id', 'name'];

    protected $hidden = ['id'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }
}
