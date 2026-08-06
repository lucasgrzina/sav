<?php

namespace App\Http\Requests\Protocols;

use Illuminate\Foundation\Http\FormRequest;

class IndexProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'root_guid'    => ['required', 'string', 'uuid', 'exists:techniques,guid'],
            'technique_id' => ['nullable', 'string', 'uuid'],
            'country_id'   => ['nullable', 'string', 'uuid'],
            'search'       => ['nullable', 'string', 'max:255'],
            'page'         => ['nullable', 'integer', 'min:1'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
