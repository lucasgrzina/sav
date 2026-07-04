<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHealthActivityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // El guid llega como segmento de ruta, no en el body.
        // Resolverlo desde el repositorio en el controller para obtener el id.
        // Aquí usamos 'ignore' por guid directamente (requiere que el modelo use guid como route key).
        $guid = $this->route('guid');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('health_activities', 'name')->where(function ($query) use ($guid) {
                    // Excluir el registro actual por guid
                    return $query->whereNot('guid', $guid);
                }),
            ],
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
