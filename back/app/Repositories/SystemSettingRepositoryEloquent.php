<?php

namespace App\Repositories;

use App\Contracts\Repositories\SystemSettingRepositoryInterface;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Collection;

class SystemSettingRepositoryEloquent implements SystemSettingRepositoryInterface
{
    public function all(): Collection
    {
        return SystemSetting::orderBy('code')->get();
    }

    public function findByCode(string $code): ?SystemSetting
    {
        return SystemSetting::where('code', $code)->first();
    }

    public function getValue(string $code, mixed $default = null): mixed
    {
        $setting = $this->findByCode($code);

        return $setting ? $setting->parsed_value : $default;
    }

    public function upsert(string $code, string $value): SystemSetting
    {
        // No modifica type ni description — esos solo cambia el seeder/migraciones
        $setting = SystemSetting::where('code', $code)->firstOrFail();
        $setting->value = $value;
        $setting->save();

        return $setting;
    }
}
