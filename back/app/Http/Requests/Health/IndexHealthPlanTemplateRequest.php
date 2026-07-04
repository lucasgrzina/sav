<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class IndexHealthPlanTemplateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'search'                    => ['nullable', 'string', 'max:100'],
            'health_plan_category_guid' => ['nullable', 'string', 'uuid'],
            'per_page'                  => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'                      => ['nullable', 'integer', 'min:1'],
        ];
    }
}
