<?php

namespace App\Http\Requests\Programs;

use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Models\Establishment;
use App\Models\Protocol;
use App\Services\UserProfileService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class StoreProgramRequest extends FormRequest
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private UserProfileService $userProfileService,
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
            'client_id'        => ['required', 'string', 'uuid', 'exists:clients,guid'],
            'establishment_id' => ['required', 'string', 'uuid', 'exists:establishments,guid'],
            'protocol_id'      => ['required', 'string', 'uuid', 'exists:protocols,guid'],
            'comments'         => ['nullable', 'string'],

            'targets'                   => ['required', 'array', 'min:1'],
            'targets.*.target_date'    => ['required', 'date'],
            'targets.*.animals'        => ['nullable', 'array'],
            'targets.*.animals.*.id'   => ['nullable', 'string', 'uuid'], // guid de Animal existente (resuelto por el picker)
            'targets.*.animals.*.rp'   => ['required', 'string', 'max:50'], // viaja siempre (DEC-07), incluso para animales existentes

            'manager_profile_ids'   => ['required', 'array', 'min:1'],
            'manager_profile_ids.*' => ['string', 'uuid'], // guids de UserProfile
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $v) {
            $vet = $this->attributes->get('current_vet');

            $client = $this->clientRepository->findByGuidForVet((string) $this->input('client_id'), $vet);
            if (!$client) {
                $v->errors()->add('client_id', 'El cliente no pertenece a esta veterinaria.');
                return;
            }

            $establishment = Establishment::where('guid', $this->input('establishment_id'))->first();
            if ($establishment && $establishment->client_id !== $client->id) {
                $v->errors()->add('establishment_id', 'El establecimiento no pertenece al cliente seleccionado.');
            }

            $protocol = Protocol::where('guid', $this->input('protocol_id'))->first();
            if ($protocol && $protocol->vet_id !== null && $protocol->vet_id !== $vet->id) {
                $v->errors()->add('protocol_id', 'El protocolo no pertenece a esta veterinaria.');
            }

            // DEC-12: un responsable puede ser staff de la vet o staff del cliente del programa.
            foreach ((array) $this->input('manager_profile_ids', []) as $index => $profileGuid) {
                $profileGuid = (string) $profileGuid;
                $belongsToVet    = $this->userProfileService->findByGuidForVet($profileGuid, $vet) !== null;
                $belongsToClient = $this->userProfileService->findByGuidForClient($profileGuid, $client) !== null;

                if (!$belongsToVet && !$belongsToClient) {
                    $v->errors()->add("manager_profile_ids.{$index}", 'El responsable seleccionado no pertenece a esta veterinaria ni al cliente.');
                }
            }

            // targets.*.animals.*.id NO se valida acá (DEC-07, regla 2): un id que no pertenece
            // al client_id del programa se descarta silenciosamente en ProgramService::syncTargetAnimals.
        });
    }

    public function messages(): array
    {
        return [
            'client_id.required'              => 'El cliente es requerido.',
            'client_id.exists'                => 'El cliente seleccionado no existe.',
            'establishment_id.required'       => 'El establecimiento es requerido.',
            'establishment_id.exists'         => 'El establecimiento seleccionado no existe.',
            'protocol_id.required'            => 'El protocolo es requerido.',
            'protocol_id.exists'              => 'El protocolo seleccionado no existe.',
            'targets.required'                => 'Debe haber al menos un objetivo.',
            'targets.min'                     => 'Debe haber al menos un objetivo.',
            'targets.*.target_date.required'  => 'La fecha objetivo es requerida.',
            'targets.*.animals.*.rp.required' => 'El RP del animal es requerido.',
            'manager_profile_ids.required'    => 'Debe seleccionar al menos un manager.',
            'manager_profile_ids.min'         => 'Debe seleccionar al menos un manager.',
        ];
    }
}
