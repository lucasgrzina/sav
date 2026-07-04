<?php

namespace App\Contracts\Repositories;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Collection;

interface SystemSettingRepositoryInterface
{
    public function all(): Collection;
    public function findByCode(string $code): ?SystemSetting;
    public function getValue(string $code, mixed $default = null): mixed;
    public function upsert(string $code, string $value): SystemSetting;
}
