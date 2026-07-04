<?php

namespace App\Http\Requests\SupportMessages;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'  => ['required', 'string', 'max:255'],
            'body'     => ['required', 'string', 'max:65000'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
            'category' => ['required', Rule::in(['bug', 'question', 'feature_request', 'other'])],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required'  => 'El asunto es requerido.',
            'subject.max'       => 'El asunto no puede superar 255 caracteres.',
            'body.required'     => 'El mensaje es requerido.',
            'body.max'          => 'El mensaje es demasiado largo.',
            'priority.required' => 'La prioridad es requerida.',
            'priority.in'       => 'La prioridad debe ser: baja, media o alta.',
            'category.required' => 'La categoría es requerida.',
            'category.in'       => 'La categoría no es válida.',
        ];
    }
}
