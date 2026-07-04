<?php

namespace App\Http\Requests\SupportMessages;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondSupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admin_response' => ['required', 'string', 'max:65000'],
            'status'         => ['required', Rule::in(['in_progress', 'closed'])],
        ];
    }

    public function messages(): array
    {
        return [
            'admin_response.required' => 'La respuesta es requerida.',
            'admin_response.max'      => 'La respuesta es demasiado larga.',
            'status.required'         => 'El estado es requerido.',
            'status.in'               => 'El estado debe ser: en proceso o cerrado.',
        ];
    }
}
