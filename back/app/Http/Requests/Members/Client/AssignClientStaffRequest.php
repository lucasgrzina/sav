<?php

namespace App\Http\Requests\Members\Client;

use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignClientStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_guid' => ['required', 'string', 'exists:users,guid'],
            'role_guid' => [
                'required',
                'string',
                Rule::exists('roles', 'guid')->where(function ($query) {
                    $query->whereIn('name', UserProfileService::CLIENT_STAFF_ROLES);
                }),
            ],
            'contacts'                  => ['nullable', 'array'],
            'contacts.*.type'           => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.value'          => ['required', 'string', 'max:200'],
            'contacts.*.label'          => ['nullable', 'string', 'max:100'],
            'contacts.*.is_primary'     => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_guid.required' => 'El usuario es obligatorio.',
            'user_guid.exists'   => 'El usuario seleccionado no existe.',
            'role_guid.required' => 'El rol es obligatorio.',
            'role_guid.exists'   => 'El rol seleccionado no es válido para staff de cliente.',
        ];
    }
}
