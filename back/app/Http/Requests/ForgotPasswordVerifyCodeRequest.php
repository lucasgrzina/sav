<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordVerifyCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'code'  => ['required', 'string', 'size:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'El token es requerido.',
            'token.size'     => 'El token debe tener exactamente 64 caracteres.',
            'code.required'  => 'El código es requerido.',
            'code.size'      => 'El código debe tener exactamente 6 dígitos.',
        ];
    }
}
