<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordReset extends Model
{
    protected $table = 'password_resets';

    protected $fillable = ['user_id', 'token', 'code', 'used'];

    protected function casts(): array
    {
        return [
            'used' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
