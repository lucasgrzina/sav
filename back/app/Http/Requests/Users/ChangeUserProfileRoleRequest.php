<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class ChangeUserProfileRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_guid' => ['required', 'string', 'exists:roles,guid'],
        ];
    }

    public function messages(): array
    {
        return [
            'role_guid.required' => 'El rol es requerido.',
            'role_guid.exists'   => 'El rol seleccionado no es válido.',
        ];
    }
}
