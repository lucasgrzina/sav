<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait ValidatesContactsArray
{
    /**
     * Retorna las reglas de validación para el array contacts.
     * Incluye guid opcional para el diff inteligente en syncContacts.
     *
     * Uso: array_merge($this->contactsRules(), [...otrasReglas])
     */
    protected function contactsRules(): array
    {
        return [
            'contacts'                  => ['nullable', 'array'],
            'contacts.*.guid'           => ['nullable', 'string', 'uuid'],
            'contacts.*.type'           => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.label'          => ['nullable', 'string', 'max:100'],
            'contacts.*.value'          => ['required', 'string', 'max:200', $this->contactValueFormatRule()],
            'contacts.*.is_primary'     => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Reproduce el mismo contrato E.164 que StoreContactRequest/UpdateContactRequest ya
     * aplican en el endpoint individual de contacto, pero resuelto contra el tipo del MISMO
     * ítem del array (contacts.N.type), no un campo top-level.
     */
    protected function contactValueFormatRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $typeAttribute = Str::replaceLast('.value', '.type', $attribute);
            $type = data_get($this->all(), $typeAttribute);

            if (! in_array($type, ['phone', 'whatsapp'], true)) {
                return;
            }

            if (! preg_match('/^\+?[1-9]\d{7,14}$/', (string) $value)) {
                $fail('El número de teléfono debe estar en formato E.164 (ej: +5491112345678).');
            }
        };
    }

    protected function contactsMessages(): array
    {
        return [
            'contacts.*.type.required'  => 'El tipo de contacto es obligatorio.',
            'contacts.*.type.in'        => 'El tipo debe ser email, phone o whatsapp.',
            'contacts.*.value.required' => 'El valor del contacto es obligatorio.',
            'contacts.*.value.max'      => 'El valor no puede superar 200 caracteres.',
            'contacts.*.guid.uuid'      => 'El guid del contacto debe ser un UUID válido.',
        ];
    }
}
