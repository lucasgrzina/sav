<?php

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

class StoreOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'      => ['required', 'string', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'      => 'El email es obligatorio.',
            'email.email'         => 'El email debe tener un formato válido.',
            'first_name.required' => 'El nombre es obligatorio.',
            'last_name.required'  => 'El apellido es obligatorio.',
        ];
    }
}
