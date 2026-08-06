<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Protocol;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use App\Models\Technique;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Carga la técnica MOET (con sus 3 sub-técnicas) y los protocolos legacy
 * definidos en database/scripts/moet_migration.json, para Argentina.
 *
 * Las alertas del JSON son una lista plana por protocolo (days_offset relativo
 * a la fecha objetivo), pero protocol_task_alerts cuelga de una tarea puntual
 * con offset_days relativo a ESA tarea. Cada alerta se asocia a la tarea con
 * mayor days_offset que sea <= al days_offset de la alerta (la próxima tarea
 * cronológicamente), heurística acordada porque el JSON no trae el mapeo
 * explícito y en algunos casos arrastra inconsistencias propias del origen.
 */
class MoetProtocolSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('database/scripts/moet_migration.json');
        if (!file_exists($path)) {
            throw new RuntimeException("No se encontró database/scripts/moet_migration.json en {$path}");
        }

        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        Schema::disableForeignKeyConstraints();
        ProtocolTaskAlert::query()->truncate();
        ProtocolTask::query()->truncate();
        Protocol::query()->truncate();
        Schema::enableForeignKeyConstraints();

        $createdById = User::role('super-admin')->value('id');
        if (!$createdById) {
            throw new RuntimeException('No existe ningún usuario con rol super-admin para asignar como created_by_id. Ejecutar después de RoleSeeder y de crear el usuario super-admin.');
        }

        $countryId = Country::where('iso_code', 'AR')->value('id');
        if (!$countryId) {
            throw new RuntimeException('No existe el país Argentina (iso_code=AR). Ejecutar después de CountrySeeder.');
        }

        $subtechniques = $this->seedTechniques($data['techniques'] ?? []);
        $this->seedProtocols($data['protocols'] ?? [], $subtechniques, $createdById, $countryId);
    }

    /** @return array<string, Technique> nombre de sub-técnica => modelo */
    private function seedTechniques(array $techniques): array
    {
        $subtechniques = [];

        foreach ($techniques as $techniqueData) {
            $root = Technique::firstOrCreate(
                ['name' => $techniqueData['name'], 'parent_id' => null],
                [
                    'guid'             => Str::uuid()->toString(),
                    'target_date_name' => $techniqueData['target_date_name'] ?? null,
                    'type'             => 'technique',
                ],
            );

            foreach ($techniqueData['children'] ?? [] as $childData) {
                $child = Technique::firstOrCreate(
                    ['name' => $childData['name'], 'parent_id' => $root->id],
                    [
                        'guid'             => Str::uuid()->toString(),
                        'target_date_name' => $childData['target_date_name'] ?? null,
                        'type'             => 'technique',
                        'protocols_name'   => $childData['protocols_name'] ?? null,
                    ],
                );

                $subtechniques[$child->name] = $child;
            }
        }

        return $subtechniques;
    }

    private function seedProtocols(array $protocols, array $subtechniques, int $createdById, int $countryId): void
    {
        foreach ($protocols as $protocolData) {
            $subtechnique = $subtechniques[$protocolData['subtechnique']] ?? null;
            if (!$subtechnique) {
                throw new RuntimeException("Sub-técnica '{$protocolData['subtechnique']}' no encontrada para el protocolo '{$protocolData['name']}'.");
            }

            $protocol = Protocol::create([
                'guid'            => Str::uuid()->toString(),
                'technique_id'    => $subtechnique->id,
                'country_id'      => $countryId,
                'vet_id'          => null,
                'created_by_type' => 'superadmin',
                'created_by_id'   => $createdById,
                'name'            => $protocolData['name'],
                'color'           => $protocolData['color'] ?? null,
            ]);

            $tasks = $this->seedTasks($protocol, $protocolData['tasks'] ?? []);
            $this->seedAlerts($tasks, $protocolData['alerts'] ?? []);
        }
    }

    /** @return \Illuminate\Support\Collection<int, ProtocolTask> tareas ordenadas por days_offset descendente (orden cronológico) */
    private function seedTasks(Protocol $protocol, array $tasksData): \Illuminate\Support\Collection
    {
        $tasks = collect();

        foreach ($tasksData as $index => $taskData) {
            $tasks->push(ProtocolTask::create([
                'guid'        => Str::uuid()->toString(),
                'protocol_id' => $protocol->id,
                'description' => $taskData['description'],
                'days_offset' => $taskData['days_offset'],
                'time_of_day' => strtolower($taskData['time_of_day']),
                'time'        => $taskData['time'],
                'important'   => $taskData['important'] ?? false,
                'sort_order'  => $index,
            ]));
        }

        return $tasks->sortByDesc('days_offset')->values();
    }

    private function seedAlerts(\Illuminate\Support\Collection $tasks, array $alertsData): void
    {
        if ($tasks->isEmpty()) {
            return;
        }

        $sortOrderByTask = [];

        foreach ($alertsData as $alertData) {
            $task = $this->resolveTaskForAlert($tasks, $alertData['days_offset']);

            $offsetDays = $alertData['days_offset'] - $task->days_offset;

            if ($offsetDays > 0) {
                $timeOfDay = 'before';
            } elseif ($offsetDays < 0) {
                $timeOfDay = 'after';
            } else {
                $timeOfDay = $alertData['time'] <= (string) $task->time ? 'before' : 'after';
            }

            $sortOrder = $sortOrderByTask[$task->id] ??= 0;

            $task->alerts()->create([
                'guid'                  => Str::uuid()->toString(),
                'offset_days'           => abs($offsetDays),
                'time_of_day'           => $timeOfDay,
                'time'                  => $alertData['time'],
                'roles'                 => $alertData['roles'],
                'message'               => $alertData['text'],
                'require_confirmation'  => $alertData['require_confirmation'] ?? false,
                'sort_order'            => $sortOrder,
            ]);

            $sortOrderByTask[$task->id]++;
        }
    }

    private function resolveTaskForAlert(\Illuminate\Support\Collection $tasksDesc, int $alertDaysOffset): ProtocolTask
    {
        $task = $tasksDesc->first(fn (ProtocolTask $t) => $t->days_offset <= $alertDaysOffset);

        return $task ?? $tasksDesc->last();
    }
}
