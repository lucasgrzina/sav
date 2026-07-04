<?php

namespace App\Http\Requests\Techniques;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTechniqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'type'     => ['nullable', Rule::in(['technique', 'vaccine'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
