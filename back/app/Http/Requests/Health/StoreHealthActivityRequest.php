<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class StoreHealthActivityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255', 'unique:health_activities,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es requerido.',
            'name.max'      => 'El nombre no puede superar 255 caracteres.',
            'name.unique'   => 'Ya existe una actividad con ese nombre.',
        ];
    }
}
