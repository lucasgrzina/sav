<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class CreateTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'             => ['required', 'string', 'max:50', 'regex:/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/'],
            'last_name'              => ['required', 'string', 'max:50', 'regex:/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/'],
            'email'                  => ['required', 'email', 'unique:users,email'],
            'password'               => [
                'required',
                'string',
                'between:8,12',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[!@#$%&]/',
            ],
            'password_confirmation'  => ['required', 'string'],
            'profiles'               => ['required', 'array', 'min:1'],
            'profiles.*.role_guid'   => ['required', 'string', 'exists:roles,guid'],
            'profiles.*.vet_guid'    => ['nullable', 'string', 'exists:vets,guid'],
            'profiles.*.client_guid' => ['nullable', 'string', 'exists:clients,guid'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required'            => 'El nombre es requerido.',
            'first_name.max'                 => 'El nombre no puede superar 50 caracteres.',
            'first_name.regex'               => 'El nombre solo puede contener letras.',
            'last_name.required'             => 'El apellido es requerido.',
            'last_name.max'                  => 'El apellido no puede superar 50 caracteres.',
            'last_name.regex'                => 'El apellido solo puede contener letras.',
            'email.required'                 => 'El email es requerido.',
            'email.email'                    => 'El email no tiene un formato válido.',
            'email.unique'                   => 'Ya existe un usuario con ese email.',
            'password.required'              => 'La contraseña es requerida.',
            'password.between'               => 'La contraseña debe tener entre 8 y 12 caracteres.',
            'password.confirmed'             => 'Las contraseñas no coinciden.',
            'password.regex'                 => 'La contraseña debe contener al menos una mayúscula, un número y un símbolo (!@#$%&).',
            'password_confirmation.required' => 'La confirmación de contraseña es requerida.',
            'profiles.required'              => 'Debe agregar al menos un perfil de acceso.',
            'profiles.min'                   => 'Debe agregar al menos un perfil de acceso.',
            'profiles.*.role_guid.required'  => 'Cada perfil debe tener un rol seleccionado.',
            'profiles.*.role_guid.exists'    => 'El rol seleccionado no es válido.',
            'profiles.*.vet_guid.exists'     => 'La veterinaria seleccionada no es válida.',
            'profiles.*.client_guid.exists'  => 'El cliente seleccionado no es válido.',
        ];
    }
}
