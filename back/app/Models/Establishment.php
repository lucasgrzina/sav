<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Establishment extends Model
{
    use HasGuid;

    protected $fillable = [
        'guid', 'client_id', 'name', 'renspa', 'address', 'city', 'state', 'zip_code', 'latitude', 'longitude',
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
}
