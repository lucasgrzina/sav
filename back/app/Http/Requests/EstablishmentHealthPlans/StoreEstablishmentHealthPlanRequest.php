<?php

namespace App\Http\Requests\EstablishmentHealthPlans;

use App\Contracts\Repositories\EstablishmentHealthPlanRepositoryInterface;
use App\Models\Establishment;
use App\Models\HealthPlanTemplate;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class StoreEstablishmentHealthPlanRequest extends FormRequest
{
    public function __construct(
        private EstablishmentHealthPlanRepositoryInterface $establishmentHealthPlanRepository,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'establishment_id'        => ['required', 'string', 'uuid', 'exists:establishments,guid'],
            'health_plan_template_id' => ['required', 'string', 'uuid', 'exists:health_plan_templates,guid'],
            'year'                    => ['required', 'integer', 'min:2020', 'max:' . (now()->year + 1)],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $v) {
            $vet = $this->attributes->get('current_vet');

            $establishment = Establishment::with('client.country')->where('guid', $this->input('establishment_id'))->first();
            if (!$establishment) {
                $v->errors()->add('establishment_id', 'El establecimiento seleccionado no existe.');
                return;
            }

            if (!$vet->clients()->whereKey($establishment->client_id)->exists()) {
                $v->errors()->add('establishment_id', 'El establecimiento no pertenece a esta veterinaria.');
                return;
            }

            if (!$establishment->client->country) {
                $v->errors()->add('establishment_id', 'El cliente no tiene país configurado.');
                return;
            }

            $template = HealthPlanTemplate::where('guid', $this->input('health_plan_template_id'))->first();
            if (!$template) {
                return; // 'exists' rule ya cubre este caso
            }

            $year = (int) $this->input('year');

            if ($this->establishmentHealthPlanRepository->existsActiveFor($establishment->id, $template->id, $year)) {
                $v->errors()->add('health_plan_template_id', 'Ya existe un plan activo para este establecimiento, plantilla y año.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'establishment_id.required'        => 'El establecimiento es requerido.',
            'establishment_id.exists'          => 'El establecimiento seleccionado no existe.',
            'health_plan_template_id.required' => 'La plantilla de plan sanitario es requerida.',
            'health_plan_template_id.exists'   => 'La plantilla seleccionada no existe.',
            'year.required'                    => 'El año es requerido.',
            'year.integer'                     => 'El año debe ser un número entero.',
            'year.min'                         => 'El año no puede ser menor a 2020.',
            'year.max'                         => 'El año no puede ser mayor al próximo año calendario.',
        ];
    }
}
