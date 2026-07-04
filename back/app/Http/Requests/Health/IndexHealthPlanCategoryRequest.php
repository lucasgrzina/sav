<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class IndexHealthPlanCategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
