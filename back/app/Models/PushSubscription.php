<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    use HasGuid;

    protected $fillable = [
        'user_id', 'device_uuid', 'endpoint', 'endpoint_hash',
        'p256dh', 'auth_key', 'content_encoding', 'device_label', 'device_updated_at',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['device_updated_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
