<?php

namespace App\Http\Requests\Protocols;

use Illuminate\Foundation\Http\FormRequest;

class IndexVetProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'technique_id' => ['nullable', 'string', 'uuid'],
            'search'       => ['nullable', 'string', 'max:255'],
            'page'         => ['nullable', 'integer', 'min:1'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
