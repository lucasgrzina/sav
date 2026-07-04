<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class StoreHealthPlanTemplateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                              => ['required', 'string', 'max:255'],
            'health_plan_category_guid'         => ['required', 'string', 'uuid', 'exists:health_plan_categories,guid'],
            'activities'                        => ['nullable', 'array'],
            'activities.*.health_activity_guid' => ['required', 'string', 'uuid', 'exists:health_activities,guid'],
            'activities.*.months'               => ['required', 'array', 'min:1'],
            'activities.*.months.*'             => ['required', 'integer', 'min:1', 'max:12'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                            => 'El nombre es requerido.',
            'health_plan_category_guid.required'       => 'La categoría es requerida.',
            'health_plan_category_guid.exists'         => 'La categoría seleccionada no existe.',
            'activities.*.health_activity_guid.exists' => 'Una de las actividades seleccionadas no existe.',
            'activities.*.months.required'             => 'Cada actividad debe tener al menos un mes asignado.',
            'activities.*.months.min'                  => 'Cada actividad debe tener al menos un mes asignado.',
            'activities.*.months.*.min'                => 'Los meses deben ser entre 1 y 12.',
            'activities.*.months.*.max'                => 'Los meses deben ser entre 1 y 12.',
        ];
    }
}
