<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasGuid
{
    public function getRouteKeyName(): string
    {
        return 'guid';
    }

    protected static function bootHasGuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->guid)) {
                $model->guid = Str::uuid()->toString();
            }
        });
    }
}
