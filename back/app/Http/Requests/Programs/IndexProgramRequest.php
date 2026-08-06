<?php

namespace App\Http\Requests\Programs;

use Illuminate\Foundation\Http\FormRequest;

class IndexProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'technique_id'     => ['nullable', 'string', 'uuid'],
            'client_id'        => ['nullable', 'string', 'uuid'],
            'establishment_id' => ['nullable', 'string', 'uuid'],
            'cancelled'        => ['nullable', 'boolean'],
            'search'           => ['nullable', 'string', 'max:255'],
            'per_page'         => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'             => ['nullable', 'integer', 'min:1'],
        ];
    }
}
