<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'           => ['sometimes', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'label'          => ['nullable', 'string', 'max:100'],
            'value'          => ['sometimes', 'string', 'max:200', $this->valueRule()],
            'is_primary'     => ['nullable', 'boolean'],
            'use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Aplica validación de formato solo si se envía el campo type en el body.
     * Si no se envía type, no podemos inferir el formato esperado.
     */
    private function valueRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $type = $this->input('type');

            if (!$type) {
                return;
            }

            if (in_array($type, ['phone', 'whatsapp'])) {
                if (!preg_match('/^\+?[1-9]\d{7,14}$/', $value)) {
                    $fail('El número de teléfono debe estar en formato E.164 (ej: +5491151234567).');
                }
            }

            if ($type === 'email') {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $fail('El email no tiene un formato válido.');
                }
            }
        };
    }

    public function messages(): array
    {
        return [
            'type.in'   => 'El tipo de contacto debe ser email, phone o whatsapp.',
            'value.max' => 'El valor no puede superar 200 caracteres.',
        ];
    }
}
