<?php

namespace App\Services;

use App\Contracts\Repositories\ProtocolRepositoryInterface;
use App\Contracts\Repositories\TechniqueRepositoryInterface;
use App\Exceptions\ProtocolHasProgramsException;
use App\Exceptions\ProtocolTechniqueLockedException;
use App\Models\Protocol;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProtocolService
{
    public function __construct(
        private ProtocolRepositoryInterface $protocolRepository,
        private TechniqueRepositoryInterface $techniqueRepository,
    ) {}

    public function paginateByRootTechnique(string $rootGuid, array $filters, int $perPage): LengthAwarePaginator
    {
        $root = $this->techniqueRepository->findRootByGuid($rootGuid);
        if (!$root) {
            throw new ModelNotFoundException('Técnica raíz no encontrada.');
        }
        return $this->protocolRepository->paginateByRootTechnique($root, $filters, $perPage);
    }

    public function findByGuidWithTasks(string $guid): ?Protocol
    {
        return $this->protocolRepository->findByGuidWithTasks($guid);
    }

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->protocolRepository->paginateForVet($vetId, $filters, $perPage);
    }

    public function findByGuidForVet(string $guid, int $vetId): ?Protocol
    {
        return $this->protocolRepository->findByGuidForVet($guid, $vetId);
    }

    /** @param array $data {technique_id (int, ya resuelto), country_id?, name, color?, tasks: [...]} */
    public function create(array $data, int $createdById, ?int $vetId = null): Protocol
    {
        return DB::transaction(function () use ($data, $createdById, $vetId) {
            $tasks = $data['tasks'] ?? [];
            unset($data['tasks']);

            $data['created_by_type'] = $vetId !== null ? 'vet' : 'superadmin';
            $data['created_by_id']   = $createdById;
            $data['vet_id']          = $vetId;
            if ($vetId !== null) {
                $data['country_id'] = null; // DEC-07: país no aplica a protocolos propios de vet
            }

            $protocol = $this->protocolRepository->create($data);
            $this->syncTasks($protocol, $tasks);

            return $protocol->fresh()->load('tasks.alerts', 'technique', 'country');
        });
    }

    /**
     * @throws ProtocolTechniqueLockedException si intenta cambiar technique_id con programs asociados
     */
    public function update(Protocol $protocol, array $data): Protocol
    {
        return DB::transaction(function () use ($protocol, $data) {
            $tasks = $data['tasks'] ?? [];
            unset($data['tasks']);

            if (isset($data['technique_id']) && $data['technique_id'] !== $protocol->technique_id) {
                $count = $this->countProgramsForProtocol($protocol);
                if ($count > 0) {
                    throw new ProtocolTechniqueLockedException($count);
                }
            }

            $this->protocolRepository->update($protocol, $data);
            $this->syncTasks($protocol, $tasks);

            return $protocol->fresh()->load('tasks.alerts', 'technique', 'country');
        });
    }

    /** @throws ProtocolHasProgramsException */
    public function destroy(Protocol $protocol): void
    {
        $count = $this->countProgramsForProtocol($protocol);
        if ($count > 0) {
            throw new ProtocolHasProgramsException($count);
        }

        DB::transaction(function () use ($protocol) {
            $protocol->load('tasks.alerts');
            foreach ($protocol->tasks as $task) {
                $task->alerts()->update(['deleted_at' => now()]);
                $task->delete();
            }
            $this->protocolRepository->destroy($protocol);
        });
    }

    /**
     * Valida duplicado name+technique_id+country_id (DU-02, ver DEC-01).
     */
    public function isDuplicateName(int $techniqueId, ?int $countryId, string $name, ?int $vetId = null, ?string $excludeGuid = null): bool
    {
        return $this->protocolRepository->existsDuplicate($techniqueId, $countryId, $name, $vetId, $excludeGuid);
    }

    /** Clona un protocolo completo (tasks + alerts) con nombre resuelto por incremento. */
    public function replicate(Protocol $protocol, int $createdById, ?int $vetId = null): Protocol
    {
        $protocol->loadMissing('tasks.alerts');

        $data = [
            'technique_id' => $protocol->technique_id,
            'country_id'   => $vetId !== null ? null : $protocol->country_id,
            'name'         => $this->resolveReplicateName($protocol, $vetId),
            'color'        => $protocol->color,
            'tasks'        => $this->mapTasksForClone($protocol->tasks),
        ];

        return $this->create($data, $createdById, $vetId);
    }

    /**
     * Calcula el cronograma de Tareas/Alertas del protocolo a partir de una fecha base,
     * SIN persistir nada (DEC-NEG-01). Devuelve dos colecciones: tasks (con alerts anidadas)
     * y alerts (lista plana con task_description), ambas ordenadas cronológicamente.
     */
    public function simulate(Protocol $protocol, Carbon $baseDate): array
    {
        $protocol->loadMissing('tasks.alerts');

        $simulatedTasks  = collect();
        $simulatedAlerts = collect();

        foreach ($protocol->tasks as $task) {
            $taskDate = $this->calculateTaskDate($baseDate, $task);

            $taskAlerts = $task->alerts->map(function (ProtocolTaskAlert $alert) use ($taskDate, $task) {
                $alertDate = $this->calculateAlertDate($taskDate, $alert);

                return [
                    'guid'                  => $alert->guid,
                    'task_description'      => $task->description,
                    'message'               => $alert->message,
                    'roles'                 => $alert->roles,
                    'require_confirmation'  => $alert->require_confirmation,
                    'computed_date'         => $alertDate->toDateString(),
                    'computed_time'         => $alertDate->format('H:i'),
                    '_sort'                 => $alertDate,
                ];
            });

            $simulatedAlerts = $simulatedAlerts->concat($taskAlerts);

            $simulatedTasks->push([
                'guid'          => $task->guid,
                'description'   => $task->description,
                'important'     => $task->important,
                'computed_date' => $taskDate->toDateString(),
                'computed_time' => $taskDate->format('H:i'),
                'alerts'        => $taskAlerts->map(fn ($a) => collect($a)->except('_sort')->all())->values()->all(),
                '_sort'         => $taskDate,
            ]);
        }

        return [
            'protocol'  => $protocol,
            'base_date' => $baseDate->toDateString(),
            'tasks'     => $simulatedTasks->sortBy('_sort')->map(fn ($t) => collect($t)->except('_sort')->all())->values()->all(),
            'alerts'    => $simulatedAlerts->sortBy('_sort')->map(fn ($a) => collect($a)->except('_sort')->all())->values()->all(),
        ];
    }

    /** Fecha de la tarea = base_date ± days_offset (según time_of_day), con hora de task.time. */
    private function calculateTaskDate(Carbon $baseDate, ProtocolTask $task): Carbon
    {
        $date = $task->time_of_day === 'after'
            ? $baseDate->copy()->addDays($task->days_offset)
            : $baseDate->copy()->subDays($task->days_offset);

        return $this->applyTime($date, $task->time);
    }

    /** Fecha de la alerta = fecha de LA TAREA (ya calculada) ± offset_days, con hora de alert.time. */
    private function calculateAlertDate(Carbon $taskDate, ProtocolTaskAlert $alert): Carbon
    {
        $date = $alert->time_of_day === 'after'
            ? $taskDate->copy()->addDays($alert->offset_days)
            : $taskDate->copy()->subDays($alert->offset_days);

        return $this->applyTime($date, $alert->time);
    }

    /** `time` es NOT NULL en protocol_tasks/protocol_task_alerts; el fallback cubre datos legacy o filas parcialmente hidratadas. */
    private function applyTime(Carbon $date, ?string $time): Carbon
    {
        if (!$time) {
            return $date->copy()->startOfDay();
        }

        [$hour, $minute] = explode(':', substr($time, 0, 5));

        return $date->copy()->setTime((int) $hour, (int) $minute);
    }

    /** DEC-04: "{name} (copia)", "(copia 2)", "(copia 3)"... hasta encontrar uno libre. */
    private function resolveReplicateName(Protocol $protocol, ?int $vetId = null): string
    {
        $candidate = $protocol->name . ' (copia)';
        $suffix = 2;
        $scopeCountryId = $vetId !== null ? null : $protocol->country_id;

        while ($this->isDuplicateName($protocol->technique_id, $scopeCountryId, $candidate, $vetId)) {
            $candidate = $protocol->name . " (copia {$suffix})";
            $suffix++;
        }

        return $candidate;
    }

    /** Mapea tasks/alerts a arrays SIN guid → syncTasks()/syncAlerts() los trata como inserts nuevos. */
    private function mapTasksForClone(\Illuminate\Support\Collection $tasks): array
    {
        return $tasks->map(fn (ProtocolTask $task) => [
            'description' => $task->description,
            'days_offset' => $task->days_offset,
            'time_of_day' => $task->time_of_day,
            'time'        => $task->time,
            'important'   => $task->important,
            'alerts'      => $task->alerts->map(fn (ProtocolTaskAlert $alert) => [
                'offset_days'          => $alert->offset_days,
                'time_of_day'          => $alert->time_of_day,
                'time'                 => $alert->time,
                'roles'                => $alert->roles,
                'message'              => $alert->message,
                'require_confirmation' => $alert->require_confirmation,
            ])->all(),
        ])->all();
    }

    /**
     * Diff por guid, mismo patrón que TechniqueRepositoryEloquent::prepareSync.
     * Tareas sin guid = nuevas. Tareas con guid ausente en $tasksData = soft-delete (+ sus alertas).
     */
    private function syncTasks(Protocol $protocol, array $tasksData): void
    {
        $existing      = $protocol->tasks()->get()->keyBy('guid');
        $incomingGuids = collect($tasksData)->pluck('guid')->filter()->values();

        // Eliminar tareas removidas (+ alertas hijas)
        $existing->whereNotIn('guid', $incomingGuids->all())->each(function (ProtocolTask $task) {
            $task->alerts()->update(['deleted_at' => now()]);
            $task->delete();
        });

        foreach ($tasksData as $index => $taskData) {
            $fields = [
                'description' => $taskData['description'],
                'days_offset' => $taskData['days_offset'],
                'time_of_day' => $taskData['time_of_day'],
                'time'        => $taskData['time'],
                'important'   => $taskData['important'] ?? false,
                'sort_order'  => $index,
            ];

            if (!empty($taskData['guid']) && $existing->has($taskData['guid'])) {
                $task = $existing->get($taskData['guid']);
                $task->fill($fields);
                $task->save();
            } else {
                $task = $protocol->tasks()->create($fields);
            }

            $this->syncAlerts($task, $taskData['alerts'] ?? []);
        }
    }

    private function syncAlerts(ProtocolTask $task, array $alertsData): void
    {
        $existing      = $task->alerts()->get()->keyBy('guid');
        $incomingGuids = collect($alertsData)->pluck('guid')->filter()->values();

        $existing->whereNotIn('guid', $incomingGuids->all())->each(fn (ProtocolTaskAlert $a) => $a->delete());

        foreach ($alertsData as $index => $alertData) {
            $fields = [
                'offset_days'          => $alertData['offset_days'] ?? 0,
                'time_of_day'          => $alertData['time_of_day'] ?? 'before',
                'time'                 => $alertData['time'],
                'roles'                => $alertData['roles'],
                'message'              => $alertData['message'],
                'require_confirmation' => $alertData['require_confirmation'] ?? false,
                'sort_order'           => $index,
            ];

            if (!empty($alertData['guid']) && $existing->has($alertData['guid'])) {
                $existing->get($alertData['guid'])->fill($fields)->save();
            } else {
                $task->alerts()->create($fields);
            }
        }
    }

    /**
     * STUB (DEC-03 / RD-03): retorna cantidad de programas creados a partir de este protocolo.
     * Reemplazar cuando exista el modelo Program:
     *   return Program::where('protocol_id', $protocol->id)->count();
     */
    private function countProgramsForProtocol(Protocol $protocol): int
    {
        // TODO: implementar cuando exista el modelo Program
        return 0;
    }
}
