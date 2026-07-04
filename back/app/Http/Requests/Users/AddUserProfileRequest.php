<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class AddUserProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_guid'   => ['required', 'string', 'exists:roles,guid'],
            'vet_guid'    => ['nullable', 'string', 'exists:vets,guid'],
            'client_guid' => ['nullable', 'string', 'exists:clients,guid'],
        ];
    }

    public function messages(): array
    {
        return [
            'role_guid.required'   => 'El rol es requerido.',
            'role_guid.exists'     => 'El rol seleccionado no es válido.',
            'vet_guid.exists'      => 'La veterinaria seleccionada no es válida.',
            'client_guid.exists'   => 'El cliente seleccionado no es válido.',
        ];
    }
}
