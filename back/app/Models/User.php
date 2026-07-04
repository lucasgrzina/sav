<?php

namespace App\Models;

use App\Traits\HasGuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasGuid, HasRoles;

    protected $fillable = [
        'guid',
        'first_name',
        'last_name',
        'name',
        'email',
        'password',
        'verification_code',
        'verification_code_expires_at',
        'verification_link_token',
        'verification_link_expires_at',
        'last_verification_email_sent_at',
        'password_changed_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
        'verification_link_token',
    ];

    public function settings(): HasMany
    {
        return $this->hasMany(UserSetting::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(UserProfile::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at'               => 'datetime',
            'password'                        => 'hashed',
            'locked_at'                       => 'datetime',
            'failed_login_attempts'           => 'integer',
            'verification_code_expires_at'    => 'datetime',
            'last_verification_email_sent_at' => 'datetime',
            'verification_link_expires_at'    => 'datetime',
            'password_changed_at'             => 'datetime',
            'last_login_at'                   => 'datetime',
        ];
    }
}
