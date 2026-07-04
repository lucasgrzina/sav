<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public const TYPE_PLATFORM = 'platform';
    public const TYPE_TENANT   = 'tenant';

    protected $fillable = ['name', 'guard_name', 'type', 'guid'];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->guid)) {
                $model->guid = Str::uuid()->toString();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'guid';
    }

    public function scopePlatform(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PLATFORM);
    }

    public function scopeTenant(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_TENANT);
    }

    public function isPlatform(): bool
    {
        return $this->type === self::TYPE_PLATFORM;
    }

    public function isTenant(): bool
    {
        return $this->type === self::TYPE_TENANT;
    }
}
