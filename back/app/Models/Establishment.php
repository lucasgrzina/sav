<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Establishment extends Model
{
    use HasGuid;

    protected $fillable = [
        'guid', 'client_id', 'name', 'renspa', 'address', 'city', 'state', 'province_id', 'zip_code', 'latitude', 'longitude',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function staff():BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'establishment_user_profile')->withTimestamps();
    }
}
