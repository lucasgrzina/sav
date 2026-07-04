<?php

namespace App\Http\Requests\Members;

use Illuminate\Foundation\Http\FormRequest;

class LookupVetStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El email es obligatorio.',
            'email.email'    => 'El email no tiene un formato válido.',
        ];
    }
}
