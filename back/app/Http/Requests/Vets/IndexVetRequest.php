<?php

namespace App\Http\Requests\Vets;

use Illuminate\Foundation\Http\FormRequest;

class IndexVetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'    => ['nullable', 'string', 'max:100'],
            'validated' => ['nullable', 'boolean'],
            'suspended' => ['nullable', 'boolean'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
