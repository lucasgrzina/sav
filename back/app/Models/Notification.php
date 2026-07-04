<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use HasGuid, SoftDeletes;

    protected $fillable = [
        'guid',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * Usuarios destinatarios de esta notificación.
     * El pivot incluye read_at para estado de lectura individual.
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_recipients')
                    ->withPivot('read_at')
                    ->withTimestamps();
    }
}
