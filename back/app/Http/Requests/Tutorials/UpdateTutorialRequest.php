<?php

namespace App\Http\Requests\Tutorials;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTutorialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'source'      => ['required', Rule::in(['youtube', 'vimeo'])],
            'code'        => ['required', 'string', 'max:255'],
            'order'       => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'  => 'El título es requerido.',
            'title.max'       => 'El título no puede superar 255 caracteres.',
            'source.required' => 'La fuente del video es requerida.',
            'source.in'       => 'La fuente debe ser youtube o vimeo.',
            'code.required'   => 'El código del video es requerido.',
            'code.max'        => 'El código del video no puede superar 255 caracteres.',
            'order.integer'   => 'El orden debe ser un número entero.',
            'order.min'       => 'El orden no puede ser negativo.',
        ];
    }
}
