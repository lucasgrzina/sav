<?php

namespace App\Http\Requests\Programs;

use App\Notifications\Enums\AlertType;
use App\Services\ProgramAlerts\ProgramAlertLineQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProgramAlertsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'   => ['nullable', 'string', Rule::in(['all', 'scheduled', 'sent', 'problem', 'cancelled'])],
            'profile'  => ['nullable', 'string', 'uuid'],
            'target'   => ['nullable', 'string', 'uuid'],
            'type'     => ['nullable', 'string', Rule::in(array_map(fn (AlertType $t) => $t->value, ProgramAlertLineQuery::TYPES))],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'      => 'El estado seleccionado no es válido.',
            'profile.uuid'   => 'La persona seleccionada no es válida.',
            'target.uuid'    => 'El grupo seleccionado no es válido.',
            'type.in'        => 'El tipo de aviso seleccionado no es válido.',
            'page.integer'   => 'La página debe ser un número entero.',
            'page.min'       => 'La página debe ser mayor o igual a 1.',
            'per_page.integer' => 'La cantidad por página debe ser un número entero.',
            'per_page.min'   => 'La cantidad por página debe ser al menos 1.',
            'per_page.max'   => 'La cantidad por página no puede superar 50.',
        ];
    }
}
