<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPasswordHistory extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = ['user_id', 'password'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
