<?php

namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'value.required' => 'El valor es requerido.',
            'value.max'      => 'El valor no puede superar los 1000 caracteres.',
        ];
    }
}
