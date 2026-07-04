<?php

namespace App\Models;

use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
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
}
