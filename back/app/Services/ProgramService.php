<?php

namespace App\Services;

use App\Contracts\Repositories\AnimalRepositoryInterface;
use App\Contracts\Repositories\ProgramRepositoryInterface;
use App\Events\ProgramCancelledEvent;
use App\Events\ProgramCreatedEvent;
use App\Events\ProgramTargetsChangedEvent;
use App\Exceptions\ProgramMustHaveOneTargetException;
use App\Exceptions\ProgramNotEditableException;
use App\Models\Program;
use App\Models\ProgramTarget;
use App\Models\Protocol;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProgramService
{
    public function __construct(
        private ProgramRepositoryInterface $programRepository,
        private AnimalRepositoryInterface $animalRepository,
    ) {}

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->programRepository->paginateForVet($vetId, $filters, $perPage);
    }

    public function findByGuidForVet(string $guid, int $vetId): ?Program
    {
        $program = $this->programRepository->findByGuidForVet($guid, $vetId);

        if ($program !== null) {
            $this->projectTargetTasks($program);
        }

        return $program;
    }

    /**
     * @param array $data {client_id, establishment_id, protocol_id (ints ya resueltos),
     *                     comments?, targets: [{target_date, animals?: [{id?: int, rp: string}]}],
     *                     manager_profile_ids: int[]}
     */
    public function create(array $data, int $vetId): Program
    {
        return DB::transaction(function () use ($data, $vetId) {
            $targets = $data['targets'];
            unset($data['targets']);

            $managerProfileIds = $data['manager_profile_ids'];
            unset($data['manager_profile_ids']);

            $data['vet_id'] = $vetId;
            $data['technique_id'] = Protocol::findOrFail($data['protocol_id'])->technique_id; // DEC-06

            $program = $this->programRepository->create($data);
            $this->syncTargets($program, $targets);
            $program->managers()->sync($managerProfileIds);

            $program = $program->fresh()->load('targets.animals', 'managers.user', 'client', 'establishment', 'technique', 'protocol');

            event(new ProgramCreatedEvent($program));
            event(new ProgramTargetsChangedEvent($program));

            return $program;
        });
    }

    /**
     * @throws ProgramNotEditableException si el programa está cancelado
     * @throws ProgramMustHaveOneTargetException si el resultado final dejaría 0 targets
     */
    public function update(Program $program, array $data): Program
    {
        if (!$program->editable) {
            throw new ProgramNotEditableException();
        }

        $targets = $data['targets'];
        unset($data['targets']);

        $incomingGuids = collect($targets)->pluck('guid')->filter()->values();
        $existingGuids = $program->targets()->pluck('guid');
        $remainingCount = $existingGuids->intersect($incomingGuids)->count()
            + collect($targets)->whereNull('guid')->count();

        if ($remainingCount === 0) {
            throw new ProgramMustHaveOneTargetException();
        }

        $managerProfileIds = $data['manager_profile_ids'];
        unset($data['manager_profile_ids']);

        $data['technique_id'] = Protocol::findOrFail($data['protocol_id'])->technique_id; // DEC-06

        return DB::transaction(function () use ($program, $data, $targets, $managerProfileIds) {
            $this->programRepository->update($program, $data);
            $this->syncTargets($program, $targets);
            $program->managers()->sync($managerProfileIds);

            $program = $program->fresh()->load('targets.animals', 'managers.user', 'client', 'establishment', 'technique', 'protocol');

            event(new ProgramTargetsChangedEvent($program));

            return $program;
        });
    }

    /** Marca cancelled_at = now(). No borra targets/animals (trazabilidad). */
    public function cancel(Program $program): Program
    {
        if (!$program->editable) {
            throw new ProgramNotEditableException();
        }

        return DB::transaction(function () use ($program) {
            $program = $this->programRepository->update($program, ['cancelled_at' => now()]);
            $program->loadMissing('managers.user');

            event(new ProgramCancelledEvent($program));

            return $program;
        });
    }

    /**
     * Diff por guid, mismo patrón que ProtocolService::syncTasks.
     * Targets sin guid = nuevos. Targets con guid ausente en $targetsData = delete()
     * (cascade borra el pivot program_target_animal).
     */
    private function syncTargets(Program $program, array $targetsData): void
    {
        $existing      = $program->targets()->get()->keyBy('guid');
        $incomingGuids = collect($targetsData)->pluck('guid')->filter()->values();

        $existing->whereNotIn('guid', $incomingGuids->all())->each(fn (ProgramTarget $target) => $target->delete());

        foreach ($targetsData as $targetData) {
            $fields = ['target_date' => $targetData['target_date']];

            if (!empty($targetData['guid']) && $existing->has($targetData['guid'])) {
                $target = $existing->get($targetData['guid']);
                $target->fill($fields);
                $target->save();
            } else {
                $target = $program->targets()->create($fields);
            }

            $this->syncTargetAnimals($target, $targetData['animals'] ?? [], $program->client_id);
        }
    }

    /**
     * Contrato { id?: int (ya resuelto de guid), rp: string } por animal, DEC-07/DEC-10.
     */
    private function syncTargetAnimals(ProgramTarget $target, array $animalsData, int $clientId): void
    {
        $animalIds = [];

        foreach ($animalsData as $animalData) {
            if (!empty($animalData['id'])) {
                $animal = $this->animalRepository->findByGuid($animalData['id']);

                if ($animal && $animal->client_id === $clientId) {
                    $animalIds[] = $animal->id;
                } else {
                    // Regla 2 confirmada: el id no pertenece al client_id del programa (o no existe).
                    // Se descarta SILENCIOSAMENTE — no se agrega al target, no se crea nada en su lugar,
                    // no aborta el resto del guardado.
                    Log::warning('Program: animal id descartado, no pertenece al client_id del programa', [
                        'animal_guid' => $animalData['id'],
                        'client_id'   => $clientId,
                    ]);
                }
                continue;
            }

            // Sin id = rp nuevo o existente DENTRO DE ESTE MISMO cliente (reglas 1 y 3 confirmadas):
            // firstOrCreateForClient reutiliza la fila si (client_id, rp) ya existe, y crea una fila
            // nueva propia de este cliente si el rp coincide con el de OTRO cliente.
            $animal = $this->animalRepository->firstOrCreateForClient($clientId, $animalData['rp']);
            $animalIds[] = $animal->id;
        }

        $target->animals()->sync($animalIds);
    }

    /**
     * DEC-15/DEC-16: proyecta tareas y alertas del protocolo sobre cada target del programa,
     * calculando fechas concretas y mapeando receptores por rol. Es una vista de solo lectura/
     * simulación: no persiste nada, no genera Alert ni dispara notificaciones reales.
     * Se invoca únicamente desde findByGuidForVet (detalle), nunca desde paginateForVet (listado).
     */
    private function projectTargetTasks(Program $program): void
    {
        $program->loadMissing('protocol.tasks.alerts', 'managers.role', 'managers.user');

        foreach ($program->targets as $target) {
            $target->projected_tasks = $program->protocol->tasks->map(function ($task) use ($target, $program) {
                $taskDate = $this->applyOffset($target->target_date, $task->days_offset, $task->time_of_day);

                return [
                    'protocol_task_guid' => $task->guid,
                    'description'        => $task->description,
                    'occurs_on'          => $taskDate->toDateString(),
                    'occurs_at'          => $task->time,
                    'important'          => $task->important,
                    'alerts'             => $task->alerts->map(function ($alert) use ($taskDate, $program) {
                        $alertDate  = $this->applyOffset($taskDate, $alert->offset_days, $alert->time_of_day);
                        $recipients = $program->managers->filter(
                            fn ($m) => in_array($m->role->name, $alert->roles, true)
                        )->values();

                        return [
                            'protocol_task_alert_guid' => $alert->guid,
                            'occurs_on'                => $alertDate->toDateString(),
                            'occurs_at'                => $alert->time,
                            'roles'                    => $alert->roles,
                            'message'                  => $alert->message,
                            'require_confirmation'     => $alert->require_confirmation,
                            'recipients'               => $recipients->map(fn ($m) => [
                                'guid' => $m->guid, 'name' => $m->user->name, 'role' => $m->role->name,
                            ])->all(),
                        ];
                    })->all(),
                ];
            })->all();
        }
    }

    private function applyOffset(\Carbon\Carbon|string $date, int $offsetDays, string $timeOfDay): \Carbon\Carbon
    {
        return \App\Support\DateOffset::apply($date, $offsetDays, $timeOfDay);
    }
}
