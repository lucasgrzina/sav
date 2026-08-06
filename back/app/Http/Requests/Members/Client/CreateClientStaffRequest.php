<?php

namespace App\Http\Requests\Members\Client;

use App\Http\Requests\Concerns\ValidatesContactsArray;
use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateClientStaffRequest extends FormRequest
{
    use ValidatesContactsArray;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'last_name'  => ['required', 'string', 'max:50'],
            'email'      => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role_guid'  => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', UserProfileService::CLIENT_STAFF_ROLES);
                }),
            ],
            'contacts'                  => ['nullable', 'array'],
            'contacts.*.type'           => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.value'          => ['required', 'string', 'max:200', $this->contactValueFormatRule()],
            'contacts.*.label'          => ['nullable', 'string', 'max:100'],
            'contacts.*.is_primary'     => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'El nombre es obligatorio.',
            'last_name.required'  => 'El apellido es obligatorio.',
            'email.required'      => 'El email es obligatorio.',
            'email.email'         => 'El email no tiene un formato válido.',
            'email.unique'        => 'Este email ya está registrado en el sistema. Usá el flujo de búsqueda.',
            'role_guid.required'  => 'El rol es obligatorio.',
            'role_guid.exists'    => 'El rol seleccionado no es válido para personal de un cliente.',
        ];
    }
}
