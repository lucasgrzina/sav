<?php

namespace App\Services;

use App\Contracts\Repositories\SystemSettingRepositoryInterface;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class SystemSettingService
{
    public function __construct(
        private SystemSettingRepositoryInterface $repository,
    ) {}

    public function list(): Collection
    {
        return $this->repository->all();
    }

    public function findByCode(string $code): ?SystemSetting
    {
        return $this->repository->findByCode($code);
    }

    public function update(string $code, string $value): SystemSetting
    {
        $setting = $this->repository->findByCode($code);

        if (! $setting) {
            throw ValidationException::withMessages(['code' => ['Configuración no encontrada.']]);
        }

        $this->validateValue($setting->type, $value);

        return $this->repository->upsert($code, $value);
    }

    private function validateValue(string $type, string $value): void
    {
        match ($type) {
            'integer' => is_numeric($value)
                ? null
                : throw ValidationException::withMessages(['value' => ['El valor debe ser un número entero.']]),
            'boolean' => in_array(strtolower($value), ['true', 'false', '1', '0'], true)
                ? null
                : throw ValidationException::withMessages(['value' => ['El valor debe ser verdadero o falso.']]),
            default => null,
        };
    }
}
