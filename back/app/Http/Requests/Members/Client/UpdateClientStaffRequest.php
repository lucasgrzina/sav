<?php

namespace App\Http\Requests\Members\Client;

use App\Http\Requests\Concerns\ValidatesContactsArray;
use App\Services\UserProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientStaffRequest extends FormRequest
{
    use ValidatesContactsArray;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(
            [
                'role_guid' => [
                    'required',
                    'string',
                    Rule::exists('roles', 'guid')->where(function ($query) {
                        $query->whereIn('name', UserProfileService::CLIENT_STAFF_ROLES);
                    }),
                ],
            ],
            $this->contactsRules(),
        );
    }

    public function messages(): array
    {
        return array_merge(
            [
                'role_guid.required' => 'El rol es obligatorio.',
                'role_guid.exists'   => 'El rol seleccionado no es válido para un miembro de cliente.',
            ],
            $this->contactsMessages(),
        );
    }
}
