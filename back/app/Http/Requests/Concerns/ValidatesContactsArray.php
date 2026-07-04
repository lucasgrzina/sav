<?php

namespace App\Http\Requests\Concerns;

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
            'contacts.*.value'          => ['required', 'string', 'max:200'],
            'contacts.*.is_primary'     => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
        ];
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
