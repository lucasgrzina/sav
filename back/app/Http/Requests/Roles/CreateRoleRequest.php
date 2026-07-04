<?php

namespace App\Http\Requests\Roles;

use Illuminate\Foundation\Http\FormRequest;

class CreateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:80', 'unique:roles,name'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['uuid', 'exists:permissions,guid'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => 'El nombre del rol es obligatorio.',
            'name.unique'          => 'Ya existe un rol con ese nombre.',
            'name.max'             => 'El nombre no puede superar los 80 caracteres.',
            'permissions.array'    => 'Los permisos deben ser un arreglo.',
            'permissions.*.uuid'   => 'Cada permiso debe ser un UUID válido.',
            'permissions.*.exists' => 'Uno o más permisos no existen.',
        ];
    }
}
