<?php

namespace App\Models;

use App\Traits\HasContacts;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserProfile extends Model
{
    use HasGuid, HasContacts;

    protected $fillable = [
        'guid', 'user_id', 'authenticatable_type', 'authenticatable_id', 'role_id', 'blocked_at',
    ];

    protected $casts = [
        'blocked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
