<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'   => ['required', 'string', 'max:50', 'regex:/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/'],
            'last_name'    => ['required', 'string', 'max:50', 'regex:/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/'],
            'email'        => ['required', 'email', Rule::unique('users', 'email')->ignore($this->route('guid'), 'guid')],
            'role_guids'   => ['nullable', 'array'],
            'role_guids.*' => ['string', 'exists:roles,guid'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'El nombre es requerido.',
            'first_name.max'      => 'El nombre no puede superar 50 caracteres.',
            'first_name.regex'    => 'El nombre solo puede contener letras.',
            'last_name.required'  => 'El apellido es requerido.',
            'last_name.max'       => 'El apellido no puede superar 50 caracteres.',
            'last_name.regex'     => 'El apellido solo puede contener letras.',
            'email.required'      => 'El email es requerido.',
            'email.email'         => 'El email no tiene un formato válido.',
            'email.unique'        => 'Ya existe un usuario con ese email.',
            'role_guids.array'    => 'Los roles deben ser un listado.',
            'role_guids.*.exists' => 'Uno o más roles seleccionados no son válidos.',
        ];
    }
}
