<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token'                 => ['required', 'string'],
            'email'                 => ['required', 'string', 'email'],
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
            'token.required'                 => 'El token de invitación es requerido.',
            'email.required'                 => 'El email es requerido.',
            'email.email'                    => 'El email no tiene un formato válido.',
            'password.required'              => 'La contraseña es requerida.',
            'password.between'               => 'La contraseña debe tener entre 8 y 12 caracteres.',
            'password.confirmed'             => 'Las contraseñas no coinciden.',
            'password.regex'                 => 'La contraseña debe contener al menos una mayúscula, un número y un símbolo (!@#$%&).',
            'password_confirmation.required' => 'La confirmación de contraseña es requerida.',
        ];
    }
}
