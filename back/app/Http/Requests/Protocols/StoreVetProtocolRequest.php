<?php

namespace App\Http\Requests\Protocols;

use App\Contracts\Repositories\ProtocolRepositoryInterface;
use App\Models\Technique;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVetProtocolRequest extends FormRequest
{
    public function __construct(private ProtocolRepositoryInterface $protocolRepository)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantRoles = ['vet', 'vet-assistant', 'vet-administrative', 'client-owner', 'client-manager', 'client-administrative'];

        return [
            'technique_id'                           => ['required', 'string', 'uuid', 'exists:techniques,guid'],
            'name'                                    => ['required', 'string', 'max:255'],
            'color'                                    => ['nullable', 'string', 'max:20'],
            'tasks'                                    => ['nullable', 'array'],
            'tasks.*.description'                      => ['required', 'string'],
            'tasks.*.days_offset'                       => ['required', 'integer', 'between:0,365'],
            'tasks.*.time_of_day'                       => ['required', Rule::in(['before', 'after'])],
            'tasks.*.time'                               => ['required', 'date_format:H:i'],
            'tasks.*.important'                          => ['nullable', 'boolean'],
            'tasks.*.alerts'                             => ['nullable', 'array'],
            'tasks.*.alerts.*.offset_days'               => ['nullable', 'integer', 'between:0,365'],
            'tasks.*.alerts.*.time_of_day'               => ['nullable', Rule::in(['before', 'after'])],
            'tasks.*.alerts.*.time'                      => ['required', 'date_format:H:i'],
            'tasks.*.alerts.*.roles'                     => ['required', 'array', 'min:1'],
            'tasks.*.alerts.*.roles.*'                   => [Rule::in($tenantRoles)],
            'tasks.*.alerts.*.message'                   => ['required', 'string'],
            'tasks.*.alerts.*.require_confirmation'      => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $v) {
            $technique = Technique::where('guid', $this->input('technique_id'))->first();
            if (!$technique) {
                return; // ya cubierto por exists:
            }
            if ($technique->parent_id === null) {
                $v->errors()->add('technique_id', 'El protocolo debe asociarse a una sub-técnica, nunca a la técnica raíz.');
                return;
            }

            $vet = $this->attributes->get('current_vet'); // Request::$attributes, disponible tras vet.tenant
            if ($this->protocolRepository->existsDuplicate($technique->id, null, (string) $this->input('name'), $vet->id)) {
                $v->errors()->add('name', 'Ya tenés un protocolo con este nombre para esta sub-técnica.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'technique_id.required'         => 'La sub-técnica es requerida.',
            'technique_id.exists'           => 'La sub-técnica seleccionada no existe.',
            'name.required'                 => 'El nombre es requerido.',
            'name.max'                      => 'El nombre no puede superar 255 caracteres.',
            'tasks.array'                   => 'Las tareas deben ser un array.',
            'tasks.*.description.required'  => 'La descripción de la tarea es requerida.',
            'tasks.*.days_offset.required'  => 'El desfase de días es requerido.',
            'tasks.*.days_offset.between'   => 'El desfase de días debe estar entre 0 y 365.',
            'tasks.*.time_of_day.required'  => 'El momento del día es requerido.',
            'tasks.*.time_of_day.in'        => 'El momento del día debe ser "before" o "after".',
            'tasks.*.time.required'         => 'La hora es requerida.',
            'tasks.*.time.date_format'      => 'La hora debe tener formato HH:MM.',
            'tasks.*.alerts.*.time.required'         => 'La hora de la alerta es requerida.',
            'tasks.*.alerts.*.time.date_format'      => 'La hora de la alerta debe tener formato HH:MM.',
            'tasks.*.alerts.*.roles.required'        => 'La alerta debe tener al menos un rol asignado.',
            'tasks.*.alerts.*.roles.min'             => 'La alerta debe tener al menos un rol asignado.',
            'tasks.*.alerts.*.message.required'      => 'El mensaje de la alerta es requerido.',
        ];
    }
}
