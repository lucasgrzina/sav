<?php

namespace App\Http\Requests\Admin\Clients;

use Illuminate\Foundation\Http\FormRequest;

class AdminLinkVetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vet_guid' => ['required', 'string', 'exists:vets,guid'],
        ];
    }

    public function messages(): array
    {
        return [
            'vet_guid.required' => 'El guid de la empresa es obligatorio.',
            'vet_guid.exists'   => 'La empresa seleccionada no existe.',
        ];
    }
}
