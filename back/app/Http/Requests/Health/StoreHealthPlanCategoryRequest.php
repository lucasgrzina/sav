<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class StoreHealthPlanCategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255', 'unique:health_plan_categories,name'],
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
