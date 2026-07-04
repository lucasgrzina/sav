<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class ChangeUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password'              => [
                'required',
                'string',
                'between:8,12',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[!@#$%&]/',
            ],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required'   => 'La contraseña es requerida.',
            'password.between'    => 'La contraseña debe tener entre 8 y 12 caracteres.',
            'password.confirmed'  => 'Las contraseñas no coinciden.',
            'password.regex'      => 'La contraseña debe contener al menos una mayúscula, un número y un símbolo (!@#$%&).',
            'password_confirmation.required' => 'La confirmación de contraseña es requerida.',
        ];
    }
}
