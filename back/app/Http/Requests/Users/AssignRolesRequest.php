<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class AssignRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles'   => ['required', 'array'],
            'roles.*' => ['uuid', 'exists:roles,guid'],
        ];
    }

    public function messages(): array
    {
        return [
            'roles.required'  => 'Debe indicar al menos un rol.',
            'roles.array'     => 'Los roles deben ser un arreglo.',
            'roles.*.uuid'    => 'Cada rol debe ser un UUID válido.',
            'roles.*.exists'  => 'Uno o más roles no existen.',
        ];
    }
}
