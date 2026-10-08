<?php

namespace App\Http\Requests\Establishments;

use Illuminate\Foundation\Http\FormRequest;

class SyncEstablishmentStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_profile_guids'   => ['present', 'array'],
            'user_profile_guids.*' => ['string', 'uuid', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_profile_guids.present'    => 'Debe indicar la lista de personal (puede estar vacía).',
            'user_profile_guids.array'      => 'La lista de personal debe ser un arreglo.',
            'user_profile_guids.*.string'   => 'Cada perfil debe ser un identificador válido.',
            'user_profile_guids.*.uuid'     => 'Cada perfil debe ser un identificador válido.',
            'user_profile_guids.*.distinct' => 'No se pueden repetir perfiles en la lista.',
        ];
    }
}
