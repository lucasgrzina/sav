<?php

namespace App\Http\Requests\Programs;

use App\Models\UserProfile;
use App\Services\ProgramService;
use App\Services\UserProfileService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class ShareProgramPdfRequest extends FormRequest
{
    public function __construct(
        private ProgramService $programService,
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
            'manager_profile_ids'   => ['required', 'array', 'min:1'],
            'manager_profile_ids.*' => ['string', 'uuid'], // guids de UserProfile
        ];
    }

    /**
     * DEC-05/DEC-06: cada guid debe pertenecer a los managers del programa y ser staff del
     * lado cliente (UserProfileService::CLIENT_STAFF_ROLES) — mismo patrón de withValidator
     * que StoreProgramRequest. El backend re-valida, nunca confía en lo que el front deshabilitó.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $v) {
            $vet  = $this->attributes->get('current_vet');
            $guid = (string) $this->route('guid');

            $program = $vet ? $this->programService->findByGuidForVet($guid, $vet->id) : null;

            if (!$program) {
                return; // 404 lo maneja el controller al re-resolver el programa
            }

            $program->loadMissing('managers.role', 'managers.contacts');

            $managersByGuid = $program->managers->keyBy('guid');

            foreach ((array) $this->input('manager_profile_ids', []) as $index => $profileGuid) {
                $profileGuid = (string) $profileGuid;
                /** @var UserProfile|null $manager */
                $manager = $managersByGuid->get($profileGuid);

                if (!$manager) {
                    $v->errors()->add("manager_profile_ids.{$index}", 'El destinatario seleccionado no pertenece a este programa.');
                    continue;
                }

                if (!in_array($manager->role->name, UserProfileService::CLIENT_STAFF_ROLES, true)) {
                    $v->errors()->add("manager_profile_ids.{$index}", 'El destinatario seleccionado no es personal del lado cliente.');
                    continue;
                }

                $hasWhatsapp = $manager->contacts->contains(
                    fn ($contact) => $contact->type->value === 'whatsapp' && $contact->use_for_alerts,
                );

                if (!$hasWhatsapp) {
                    $v->errors()->add("manager_profile_ids.{$index}", 'El destinatario seleccionado no tiene un contacto de WhatsApp habilitado.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'manager_profile_ids.required' => 'Debe seleccionar al menos un destinatario.',
            'manager_profile_ids.min'       => 'Debe seleccionar al menos un destinatario.',
        ];
    }
}
