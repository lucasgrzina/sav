<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSetting;

class UserSettingService
{
    /**
     * Retorna todas las configuraciones del usuario como mapa code → valor_parseado.
     * @return array<string, mixed>
     */
    public function getAll(User $user): array
    {
        return UserSetting::where('user_id', $user->id)
            ->get()
            ->mapWithKeys(fn (UserSetting $s) => [$s->code => $s->parsed_value])
            ->toArray();
    }

    /**
     * Inserta o actualiza una configuración.
     * El tipo se deriva automáticamente del valor PHP recibido.
     */
    public function set(User $user, string $code, mixed $value): void
    {
        $type       = $this->deriveType($value);
        $serialized = $this->serialize($value, $type);

        UserSetting::updateOrCreate(
            ['user_id' => $user->id, 'code' => $code],
            ['value' => $serialized, 'type' => $type],
        );
    }

    private function deriveType(mixed $value): string
    {
        if (is_bool($value))  return 'boolean';
        if (is_int($value))   return 'integer';
        if (is_array($value)) return 'json';
        return 'string';
    }

    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json'    => json_encode($value),
            default   => (string) $value,
        };
    }
}
