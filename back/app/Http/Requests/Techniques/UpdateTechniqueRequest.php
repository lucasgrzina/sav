<?php

namespace App\Http\Requests\Techniques;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTechniqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                        => ['required', 'string', 'max:255'],
            'type'                        => ['required', Rule::in(['technique', 'vaccine'])],
            'target_date_name'            => ['nullable', 'string', 'max:255'],
            'protocols_name'              => ['nullable', 'string', 'max:255'],
            'children'                    => ['nullable', 'array', 'max:50'],
            'children.*.guid'             => ['nullable', 'string', 'uuid'],
            'children.*.name'             => ['required', 'string', 'max:255'],
            'children.*.protocols_name'   => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'            => 'El nombre es requerido.',
            'name.max'                 => 'El nombre no puede superar 255 caracteres.',
            'type.required'            => 'El tipo es requerido.',
            'type.in'                  => 'El tipo debe ser "technique" o "vaccine".',
            'children.array'           => 'Las sub-técnicas deben ser un array.',
            'children.max'             => 'No se pueden agregar más de 50 sub-técnicas.',
            'children.*.name.required' => 'El nombre de la sub-técnica es requerido.',
            'children.*.name.max'      => 'El nombre de la sub-técnica no puede superar 255 caracteres.',
            'children.*.guid.uuid'     => 'El identificador de la sub-técnica no es válido.',
        ];
    }
}
