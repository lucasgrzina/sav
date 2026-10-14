<?php

namespace App\Services;

use App\Contracts\Repositories\AnimalRepositoryInterface;
use App\Contracts\Repositories\ProgramRepositoryInterface;
use App\Events\ProgramCancelledEvent;
use App\Events\ProgramCreatedEvent;
use App\Exceptions\ProgramManagerNotLinkedException;
use App\Exceptions\ProgramMustHaveOneTargetException;
use App\Exceptions\ProgramNotEditableException;
use App\Models\Program;
use App\Models\ProgramTarget;
use App\Models\Protocol;
use App\Services\ProgramAlerts\Data\ProjectedAlert;
use App\Services\ProgramAlerts\Data\ProjectedRecipient;
use App\Services\ProgramAlerts\Data\ProjectedTask;
use App\Services\ProgramAlerts\Data\ExistingAlertIndex;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\Data\WarningMode;
use App\Services\ProgramAlerts\ProgramAlertProjector;
use App\Services\ProgramAlerts\ProgramAlertReconciler;
use App\Services\ProgramAlerts\ProgramAlertWarningService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProgramService
{
    public function __construct(
        private ProgramRepositoryInterface $programRepository,
        private AnimalRepositoryInterface $animalRepository,
        private ProgramAlertProjector $projector,
        private ProgramAlertReconciler $reconciler,
        private ?ProgramAlertWarningService $warningService = null,
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
     *
     * @throws ProgramManagerNotLinkedException si un manager de cliente no está vinculado al establecimiento
     */
    public function create(array $data, int $vetId): Program
    {
        $this->assertClientManagersLinked($data['establishment_id'], $data['manager_profile_ids']);

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
            $this->reconcileAlerts($program, 'program_created');

            return $program;
        });
    }

    /**
     * @throws ProgramNotEditableException si el programa está cancelado
     * @throws ProgramMustHaveOneTargetException si el resultado final dejaría 0 targets
     * @throws ProgramManagerNotLinkedException si un manager de cliente no está vinculado al establecimiento
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

        $this->assertClientManagersLinked($data['establishment_id'], $data['manager_profile_ids']);

        $managerProfileIds = $data['manager_profile_ids'];
        unset($data['manager_profile_ids']);

        $data['technique_id'] = Protocol::findOrFail($data['protocol_id'])->technique_id; // DEC-06

        return DB::transaction(function () use ($program, $data, $targets, $managerProfileIds) {
            $this->programRepository->update($program, $data);
            $this->syncTargets($program, $targets);
            $program->managers()->sync($managerProfileIds);

            $program = $program->fresh()->load('targets.animals', 'managers.user', 'client', 'establishment', 'technique', 'protocol');

            $this->reconcileAlerts($program, 'program_updated');

            return $program;
        });
    }

    /**
     * Syncs the persisted alerts and leaves the report and the warnings on the model for the
     * response layer. The warnings are computed AFTER reconciling, over what is really persisted,
     * so they are authoritative (an alert that turned into the past since the preview shows up here).
     * They never block the save (DU-01).
     */
    private function reconcileAlerts(Program $program, string $trigger): void
    {
        $report = $this->reconciler->reconcile($program, $trigger, auth()->id());

        try {
            $input = ProjectionInput::fromProgram($program, CarbonImmutable::instance(now()));
            $report->warnings = ($this->warningService ??= app(ProgramAlertWarningService::class))->build(
                $input,
                $this->projector->project($input),
                WarningMode::Save,
                ExistingAlertIndex::forProgram($program),
            );
        } catch (Throwable $e) {
            // Informational only (DU-01): a failure computing them must never roll back the save.
            report($e);
            $report->warnings = [];
        }

        $program->setRelation('alert_report', $report); // relation, not attribute: attributes would be persisted by later saves
        $program->setRelation('alert_warnings', $report->warnings);
    }

    /**
     * Client-type managers must be linked to the program's establishment; vet-type managers are exempt.
     *
     * @param int[] $managerProfileIds
     * @throws ProgramManagerNotLinkedException
     */
    private function assertClientManagersLinked(int $establishmentId, array $managerProfileIds): void
    {
        if ($this->programRepository->unlinkedClientManagerIds($establishmentId, $managerProfileIds) !== []) {
            throw new ProgramManagerNotLinkedException();
        }
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
     * DEC-15/DEC-16: proyecta tareas y alertas del protocolo sobre cada target del programa.
     * Vista de solo lectura: no persiste nada ni dispara notificaciones. El cálculo lo hace
     * ProgramAlertProjector, el mismo que usa la generación real de alertas (RT-01), así el
     * detalle y lo realmente programado no pueden divergir.
     * Se invoca únicamente desde findByGuidForVet (detalle), nunca desde paginateForVet (listado).
     * `occurs_at` se devuelve como hora LOCAL del vet (sin zona), igual que antes; el contrato se
     * extiende con `scheduled_at_local` (objeto con zona), `timing` y `skip_reason` por alerta.
     */
    private function projectTargetTasks(Program $program): void
    {
        $projection = $this->projector->project(
            ProjectionInput::fromProgram($program, CarbonImmutable::instance(now()))
        );

        foreach ($program->targets as $target) {
            $target->projected_tasks = array_map(fn (ProjectedTask $task) => [
                'protocol_task_guid' => $task->protocolTaskGuid,
                'description'        => $task->description,
                'occurs_on'          => $task->date,
                'occurs_at'          => $task->time,
                'important'          => $task->important,
                'alerts'             => array_map(fn (ProjectedAlert $alert) => [
                    'protocol_task_alert_guid' => $alert->protocolTaskAlertGuid,
                    'occurs_on'                => $alert->scheduledAtLocal->date,
                    'occurs_at'                => $alert->alertTime,
                    'roles'                    => $alert->roles,
                    'message'                  => $alert->message,
                    'require_confirmation'     => $alert->requireConfirmation,
                    'recipients'               => array_map(fn (ProjectedRecipient $recipient) => [
                        'guid' => $recipient->profile->guid,
                        'name' => $recipient->profile->user->name,
                        'role' => $recipient->role,
                    ], $alert->recipients),
                    'scheduled_at_local'       => $alert->scheduledAtLocal->toArray(),
                    'timing'                   => $alert->isPast ? 'past' : 'future',
                    'skip_reason'              => $alert->recipients === [] ? 'no_recipients' : null,
                ], $task->alerts),
            ], $projection->tasksForTarget($target->guid));
        }
    }

    private function applyOffset(\Carbon\Carbon|string $date, int $offsetDays, string $timeOfDay): \Carbon\Carbon
    {
        return \App\Support\DateOffset::apply($date, $offsetDays, $timeOfDay);
    }

    /**
     * DEC-03/DEC-04: proyecta las tareas del protocolo sobre cada target del programa para
     * el PDF de detalle. Reusa el mismo cálculo de fecha real (DateOffset::apply) que
     * projectTargetTasks, pero sin el bloque de recipients/alerts en detalle — el PDF solo
     * necesita saber SI la tarea notifica (booleano), no a quién ni con qué mensaje.
     *
     * @return array<int, array{target: ProgramTarget, tasks: array}>
     */
    public function projectTasksForPdf(Program $program): array
    {
        $program->loadMissing('protocol.tasks.alerts', 'targets.animals');

        return $program->targets->map(function (ProgramTarget $target) use ($program) {
            $tasks = $program->protocol->tasks->map(function ($task) use ($target) {
                return [
                    'description' => $task->description,
                    'occurs_on'   => $this->applyOffset($target->target_date, $task->days_offset, $task->time_of_day)->toDateString(),
                    'occurs_at'   => $task->time,
                    'important'   => $task->important,
                    'notifies'    => $task->alerts->isNotEmpty(),
                ];
            })->sortBy('occurs_on')->values()->all();

            return ['target' => $target, 'tasks' => $tasks];
        })->all();
    }
}
