<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHealthPlanCategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $guid = $this->route('guid');
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('health_plan_categories', 'name')->where(
                    fn($q) => $q->whereNot('guid', $guid)
                ),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es requerido.',
            'name.unique'   => 'Ya existe una categoría con ese nombre.',
        ];
    }
}
