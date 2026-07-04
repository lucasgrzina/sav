<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'  => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_\.]*$/'],
            'value' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'El código solo puede contener letras minúsculas, números, guiones bajos y puntos.',
        ];
    }
}
