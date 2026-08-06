<?php

namespace App\Http\Requests\Protocols;

use Illuminate\Foundation\Http\FormRequest;

class SimulateProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'base_date.required'    => 'La fecha base es obligatoria.',
            'base_date.date_format' => 'La fecha base debe tener el formato AAAA-MM-DD.',
        ];
    }
}
