<?php

namespace App\Http\Requests\Programs;

use App\Helpers\ResponseHelper;
use App\Models\Program;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Same body as store/update minus the animals (optional and ignored). Used by both
 * POST programs/preview (create) and POST programs/{guid}/preview (edit); the edit route
 * additionally requires every `targets.*.guid` to belong to that program.
 */
class PreviewProgramRequest extends StoreProgramRequest
{
    private ?Program $program = null;

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'targets.*.guid'       => ['nullable', 'string', 'uuid'],
            'targets.*.target_ref' => ['nullable', 'string', 'max:36'], // client-side id of a group that has no guid yet
        ]);
    }

    /** The program being edited (edit route only); null on the create route. */
    public function program(): ?Program
    {
        return $this->program;
    }

    protected function prepareForValidation(): void
    {
        $guid = $this->route('guid');

        if ($guid === null) {
            return;
        }

        $vet = $this->attributes->get('current_vet');
        $this->program = Program::where('guid', $guid)->where('vet_id', $vet->id)->first();

        if ($this->program === null) {
            throw new HttpResponseException(ResponseHelper::notFoundResponse('Programa no encontrado.'));
        }
    }

    public function withValidator(ValidatorContract $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (ValidatorContract $v) {
            if ($this->program === null) {
                return;
            }

            $own = $this->program->targets()->pluck('guid')->all();

            foreach ((array) $this->input('targets', []) as $index => $target) {
                $guid = $target['guid'] ?? null;

                if ($guid !== null && ! in_array($guid, $own, true)) {
                    $v->errors()->add("targets.{$index}.guid", 'El grupo no pertenece a este programa.');
                }
            }
        });
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'targets.*.guid.uuid'        => 'El identificador del grupo no es válido.',
            'targets.*.target_ref.max'   => 'La referencia del grupo es demasiado larga.',
        ]);
    }
}
