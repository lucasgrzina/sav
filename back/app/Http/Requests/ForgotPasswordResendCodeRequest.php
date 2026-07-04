<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordResendCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guid' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'guid.required' => 'El identificador de usuario es requerido.',
        ];
    }
}
