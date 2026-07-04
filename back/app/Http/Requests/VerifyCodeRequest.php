<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guid' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'guid.required' => 'El identificador de usuario es requerido.',
            'code.required' => 'El código es requerido.',
            'code.size'     => 'El código debe tener exactamente 6 dígitos.',
        ];
    }
}
