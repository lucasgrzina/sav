<?php

namespace App\Http\Requests\EstablishmentHealthPlans;

use Illuminate\Foundation\Http\FormRequest;

class IndexEstablishmentHealthPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'        => ['nullable', 'string', 'uuid'],
            'establishment_id' => ['nullable', 'string', 'uuid'],
            'cancelled'        => ['nullable', 'boolean'],
            'search'           => ['nullable', 'string', 'max:255'],
            'per_page'         => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'             => ['nullable', 'integer', 'min:1'],
        ];
    }
}
