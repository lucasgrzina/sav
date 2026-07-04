<?php

namespace App\Http\Requests\SupportMessages;

use Illuminate\Foundation\Http\FormRequest;

class CreateReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:65000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'El mensaje es requerido.',
            'body.max'      => 'El mensaje es demasiado largo.',
        ];
    }
}
