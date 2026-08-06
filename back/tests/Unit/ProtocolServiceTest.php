<?php

namespace Tests\Unit;

use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Protocol;
use App\Models\Technique;
use App\Models\Vet;
use App\Repositories\ProtocolRepositoryEloquent;
use App\Repositories\TechniqueRepositoryEloquent;
use App\Services\ProtocolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProtocolServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProtocolService $service;
    private Technique $root;
    private Technique $subTechnique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProtocolService(new ProtocolRepositoryEloquent(), new TechniqueRepositoryEloquent());

        $this->root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Inseminación Artificial',
            'type' => 'technique',
        ]);

        $this->subTechnique = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'IA a tiempo fijo',
            'type'      => 'technique',
            'parent_id' => $this->root->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // create
    // -------------------------------------------------------------------------

    public function test_create_creates_protocol_with_nested_tasks_and_alerts_in_transaction(): void
    {
        $protocol = $this->service->create([
            'technique_id' => $this->subTechnique->id,
            'name'         => 'Protocolo IATF estándar',
            'color'        => '#3B82F6',
            'country_id'   => null,
            'tasks'        => [
                [
                    'description' => 'Colocar CIDR',
                    'days_offset' => 0,
                    'time_of_day' => 'before',
                    'time'        => '08:00',
                    'important'   => true,
                    'alerts'      => [
                        [
                            'offset_days'           => 1,
                            'time_of_day'           => 'before',
                            'time'                  => '08:00',
                            'roles'                 => ['vet'],
                            'message'               => 'Recordatorio: colocar CIDR mañana',
                            'require_confirmation'  => false,
                        ],
                    ],
                ],
            ],
        ], 1);

        $this->assertEquals('Protocolo IATF estándar', $protocol->name);
        $this->assertCount(1, $protocol->tasks);
        $this->assertCount(1, $protocol->tasks->first()->alerts);
        $this->assertEquals('superadmin', $protocol->created_by_type);
        $this->assertEquals(1, $protocol->created_by_id);

        $this->assertDatabaseHas('protocols', ['name' => 'Protocolo IATF estándar']);
        $this->assertDatabaseHas('protocol_tasks', ['description' => 'Colocar CIDR']);
        $this->assertDatabaseHas('protocol_task_alerts', ['message' => 'Recordatorio: colocar CIDR mañana']);
    }

    public function test_create_without_tasks(): void
    {
        $protocol = $this->service->create([
            'technique_id' => $this->subTechnique->id,
            'name'         => 'Protocolo vacío',
            'country_id'   => null,
        ], 1);

        $this->assertCount(0, $protocol->tasks);
    }

    private function createVet(string $name = 'Vet Test'): Vet
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'A' . Str::random(1),
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid'             => Str::uuid()->toString(),
            'country_id'       => $country->id,
            'name'             => 'CUIT',
            'validation_regex' => '.*',
        ]);

        return Vet::create([
            'guid'             => Str::uuid()->toString(),
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::random(6),
            'country_id'       => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id'           => '20-12345678-9',
            'validated_at'     => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // create — con vetId (protocolos propios de una vet)
    // -------------------------------------------------------------------------

    public function test_create_with_vet_id_persists_created_by_type_vet_and_null_country(): void
    {
        $vet = $this->createVet();
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Chile',
            'iso_code'     => 'CL',
            'phone_prefix' => '+56',
        ]);

        $protocol = $this->service->create([
            'technique_id' => $this->subTechnique->id,
            'name'         => 'Protocolo de vet',
            'country_id'   => $country->id, // debe ser ignorado (DEC-07)
        ], 1, $vet->id);

        $this->assertEquals('vet', $protocol->created_by_type);
        $this->assertEquals($vet->id, $protocol->vet_id);
        $this->assertNull($protocol->country_id);
    }

    // -------------------------------------------------------------------------
    // replicate — con vetId (DEC-05)
    // -------------------------------------------------------------------------

    public function test_replicate_with_vet_id_creates_new_protocol_owned_by_vet(): void
    {
        $vet = $this->createVet();
        $protocol = $this->service->create($this->protocolPayload(), 1);

        $version = $this->service->replicate($protocol, 1, $vet->id);

        $this->assertNotEquals($protocol->guid, $version->guid);
        $this->assertEquals($vet->id, $version->vet_id);
        $this->assertEquals('vet', $version->created_by_type);
        $this->assertNull($version->country_id);

        $original = Protocol::where('guid', $protocol->guid)->firstOrFail();
        $this->assertNull($original->vet_id);
        $this->assertEquals('Protocolo IATF estándar', $original->name);
    }

    // -------------------------------------------------------------------------
    // update — agrega/quita/reordena tareas y alertas
    // -------------------------------------------------------------------------

    public function test_update_adds_removes_and_reorders_tasks(): void
    {
        $protocol = $this->service->create([
            'technique_id' => $this->subTechnique->id,
            'name'         => 'Protocolo original',
            'country_id'   => null,
            'tasks'        => [
                ['description' => 'Tarea 1', 'days_offset' => 0, 'time_of_day' => 'before', 'time' => '08:00', 'important' => false, 'alerts' => []],
                ['description' => 'Tarea 2', 'days_offset' => 1, 'time_of_day' => 'after', 'time' => '09:00', 'important' => false, 'alerts' => []],
            ],
        ], 1);

        $task1Guid = $protocol->tasks->firstWhere('description', 'Tarea 1')->guid;

        // Update: elimina Tarea 2, reordena Tarea 1 al índice 1, agrega Tarea 3 al índice 0
        $updated = $this->service->update($protocol, [
            'name'       => 'Protocolo original',
            'country_id' => null,
            'tasks'      => [
                ['description' => 'Tarea 3', 'days_offset' => 2, 'time_of_day' => 'after', 'time' => '10:00', 'important' => false, 'alerts' => []],
                ['guid' => $task1Guid, 'description' => 'Tarea 1 editada', 'days_offset' => 0, 'time_of_day' => 'before', 'time' => '08:00', 'important' => true, 'alerts' => []],
            ],
        ]);

        $tasks = $updated->tasks->sortBy('sort_order')->values();

        $this->assertCount(2, $tasks);
        $this->assertEquals('Tarea 3', $tasks[0]->description);
        $this->assertEquals(0, $tasks[0]->sort_order);
        $this->assertEquals('Tarea 1 editada', $tasks[1]->description);
        $this->assertEquals(1, $tasks[1]->sort_order);

        $this->assertDatabaseMissing('protocol_tasks', ['description' => 'Tarea 2', 'deleted_at' => null]);
    }

    public function test_update_does_not_block_technique_change_when_no_programs(): void
    {
        $protocol = $this->service->create([
            'technique_id' => $this->subTechnique->id,
            'name'         => 'Protocolo movible',
            'country_id'   => null,
        ], 1);

        $otherSubTechnique = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'IA convencional',
            'type'      => 'technique',
            'parent_id' => $this->root->id,
        ]);

        // countProgramsForProtocol() siempre retorna 0 (stub), por lo que el cambio no debe bloquearse.
        $updated = $this->service->update($protocol, [
            'technique_id' => $otherSubTechnique->id,
            'name'         => 'Protocolo movible',
            'country_id'   => null,
        ]);

        $this->assertEquals($otherSubTechnique->id, $updated->technique_id);
    }

    // -------------------------------------------------------------------------
    // replicate
    // -------------------------------------------------------------------------

    private function protocolPayload(array $overrides = []): array
    {
        return array_merge([
            'technique_id' => $this->subTechnique->id,
            'name'         => 'Protocolo IATF estándar',
            'color'        => '#3B82F6',
            'country_id'   => null,
            'tasks'        => [
                [
                    'description' => 'Colocar CIDR',
                    'days_offset' => 0,
                    'time_of_day' => 'before',
                    'time'        => '08:00',
                    'important'   => true,
                    'alerts'      => [
                        [
                            'offset_days'          => 1,
                            'time_of_day'          => 'before',
                            'time'                 => '08:00',
                            'roles'                => ['vet'],
                            'message'              => 'Recordatorio: colocar CIDR mañana',
                            'require_confirmation' => false,
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    public function test_replicate_uses_copia_suffix_without_collision(): void
    {
        $protocol = $this->service->create($this->protocolPayload(), 1);

        $clone = $this->service->replicate($protocol, 1);

        $this->assertEquals('Protocolo IATF estándar (copia)', $clone->name);
    }

    public function test_replicate_increments_suffix_on_one_collision(): void
    {
        $protocol = $this->service->create($this->protocolPayload(), 1);
        $this->service->create($this->protocolPayload(['name' => 'Protocolo IATF estándar (copia)']), 1);

        $clone = $this->service->replicate($protocol, 1);

        $this->assertEquals('Protocolo IATF estándar (copia 2)', $clone->name);
    }

    public function test_replicate_increments_suffix_past_multiple_collisions(): void
    {
        $protocol = $this->service->create($this->protocolPayload(), 1);
        $this->service->create($this->protocolPayload(['name' => 'Protocolo IATF estándar (copia)']), 1);
        $this->service->create($this->protocolPayload(['name' => 'Protocolo IATF estándar (copia 2)']), 1);

        $clone = $this->service->replicate($protocol, 1);

        $this->assertEquals('Protocolo IATF estándar (copia 3)', $clone->name);
    }

    public function test_replicate_clones_tasks_and_alerts_with_new_guids(): void
    {
        $protocol = $this->service->create($this->protocolPayload(), 1);
        $originalTask  = $protocol->tasks->first();
        $originalAlert = $originalTask->alerts->first();

        $clone = $this->service->replicate($protocol, 1);
        $cloneTask  = $clone->tasks->first();
        $cloneAlert = $cloneTask->alerts->first();

        $this->assertNotEquals($protocol->guid, $clone->guid);
        $this->assertNotEquals($originalTask->guid, $cloneTask->guid);
        $this->assertNotEquals($originalAlert->guid, $cloneAlert->guid);

        $this->assertEquals($originalTask->days_offset, $cloneTask->days_offset);
        $this->assertEquals($originalTask->time_of_day, $cloneTask->time_of_day);
        $this->assertEquals($originalTask->important, $cloneTask->important);
        $this->assertEquals($originalAlert->roles, $cloneAlert->roles);
        $this->assertEquals($originalAlert->message, $cloneAlert->message);
        $this->assertEquals($originalAlert->require_confirmation, $cloneAlert->require_confirmation);
    }

    public function test_replicate_does_not_mutate_original_protocol(): void
    {
        $protocol = $this->service->create($this->protocolPayload(), 1);

        $this->service->replicate($protocol, 1);

        $original = Protocol::where('guid', $protocol->guid)->firstOrFail();
        $this->assertEquals('Protocolo IATF estándar', $original->name);
        $this->assertCount(1, $original->tasks);
    }

    // -------------------------------------------------------------------------
    // simulate
    // -------------------------------------------------------------------------

    public function test_simulate_same_day_task_when_days_offset_is_zero(): void
    {
        $protocol = $this->service->create($this->protocolPayload([
            'tasks' => [
                ['description' => 'Tarea D0', 'days_offset' => 0, 'time_of_day' => 'before', 'time' => '08:00', 'important' => false, 'alerts' => []],
            ],
        ]), 1);

        $result = $this->service->simulate($protocol, \Illuminate\Support\Carbon::parse('2026-08-15'));

        $this->assertEquals('2026-08-15', $result['tasks'][0]['computed_date']);
        $this->assertEquals('08:00', $result['tasks'][0]['computed_time']);
    }

    public function test_simulate_before_task_subtracts_days_offset(): void
    {
        $protocol = $this->service->create($this->protocolPayload([
            'tasks' => [
                ['description' => 'Tarea antes', 'days_offset' => 5, 'time_of_day' => 'before', 'time' => '08:00', 'important' => false, 'alerts' => []],
            ],
        ]), 1);

        $result = $this->service->simulate($protocol, \Illuminate\Support\Carbon::parse('2026-08-15'));

        $this->assertEquals('2026-08-10', $result['tasks'][0]['computed_date']);
    }

    public function test_simulate_alert_date_is_relative_to_computed_task_date_not_base_date(): void
    {
        // Tarea 'after' +10 días desde base_date, alerta 'before' -2 días desde LA TAREA (no desde base_date).
        $protocol = $this->service->create($this->protocolPayload([
            'tasks' => [
                [
                    'description' => 'Tarea after',
                    'days_offset' => 10,
                    'time_of_day' => 'after',
                    'time'        => '09:00',
                    'important'   => false,
                    'alerts'      => [
                        ['offset_days' => 2, 'time_of_day' => 'before', 'time' => '07:00', 'roles' => ['vet'], 'message' => 'msg', 'require_confirmation' => false],
                    ],
                ],
            ],
        ]), 1);

        $result = $this->service->simulate($protocol, \Illuminate\Support\Carbon::parse('2026-08-15'));

        // base_date=15/08, tarea = 25/08 (base+10), alerta = tarea-2 = 23/08 (NO 13/08, que sería base_date-2)
        $this->assertEquals('2026-08-25', $result['tasks'][0]['computed_date']);
        $this->assertEquals('2026-08-23', $result['tasks'][0]['alerts'][0]['computed_date']);
        $this->assertEquals('07:00', $result['tasks'][0]['alerts'][0]['computed_time']);
        $this->assertEquals('2026-08-23', $result['alerts'][0]['computed_date']);
    }

    public function test_simulate_returns_empty_collections_for_protocol_without_tasks(): void
    {
        $protocol = $this->service->create([
            'technique_id' => $this->subTechnique->id,
            'name'         => 'Protocolo sin tareas',
            'country_id'   => null,
        ], 1);

        $result = $this->service->simulate($protocol, \Illuminate\Support\Carbon::parse('2026-08-15'));

        $this->assertEquals([], $result['tasks']);
        $this->assertEquals([], $result['alerts']);
    }

    public function test_simulate_does_not_mutate_protocol_or_persist_anything(): void
    {
        $protocol = $this->service->create($this->protocolPayload(), 1);
        $tasksCountBefore = \App\Models\ProtocolTask::count();
        $alertsCountBefore = \App\Models\ProtocolTaskAlert::count();

        $this->service->simulate($protocol, \Illuminate\Support\Carbon::parse('2026-08-15'));

        $this->assertEquals('Colocar CIDR', $protocol->tasks->first()->description);
        $this->assertEquals($tasksCountBefore, \App\Models\ProtocolTask::count());
        $this->assertEquals($alertsCountBefore, \App\Models\ProtocolTaskAlert::count());
    }

    public function test_simulate_orders_tasks_and_alerts_chronologically(): void
    {
        $protocol = $this->service->create($this->protocolPayload([
            'tasks' => [
                ['description' => 'Tarea tardía', 'days_offset' => 10, 'time_of_day' => 'after', 'time' => '08:00', 'important' => false, 'alerts' => []],
                ['description' => 'Tarea temprana', 'days_offset' => 5, 'time_of_day' => 'before', 'time' => '08:00', 'important' => false, 'alerts' => []],
            ],
        ]), 1);

        $result = $this->service->simulate($protocol, \Illuminate\Support\Carbon::parse('2026-08-15'));

        $this->assertEquals('Tarea temprana', $result['tasks'][0]['description']);
        $this->assertEquals('Tarea tardía', $result['tasks'][1]['description']);
    }

    // -------------------------------------------------------------------------
    // destroy
    // -------------------------------------------------------------------------

    public function test_destroy_soft_deletes_protocol_and_cascades_tasks_and_alerts(): void
    {
        $protocol = $this->service->create([
            'technique_id' => $this->subTechnique->id,
            'name'         => 'Protocolo a eliminar',
            'country_id'   => null,
            'tasks'        => [
                [
                    'description' => 'Tarea única',
                    'days_offset' => 0,
                    'time_of_day' => 'before',
                    'time'        => '08:00',
                    'important'   => false,
                    'alerts'      => [
                        ['offset_days' => 0, 'time_of_day' => 'before', 'time' => '08:00', 'roles' => ['vet'], 'message' => 'msg', 'require_confirmation' => false],
                    ],
                ],
            ],
        ], 1);

        $taskId  = $protocol->tasks->first()->id;
        $alertId = $protocol->tasks->first()->alerts->first()->id;

        $this->service->destroy($protocol);

        $this->assertSoftDeleted('protocols', ['id' => $protocol->id]);
        $this->assertSoftDeleted('protocol_tasks', ['id' => $taskId]);
        $this->assertSoftDeleted('protocol_task_alerts', ['id' => $alertId]);
    }

    /**
     * @todo Habilitar cuando exista el modelo Program y countProgramsForProtocol() deje de ser un stub.
     * Hoy el stub siempre retorna 0, así que el camino "bloqueado por programas" no es testeable
     * sin mockear/reflexionar sobre un método privado.
     */
    public function test_destroy_blocks_when_protocol_has_programs(): void
    {
        $this->markTestSkipped('countProgramsForProtocol() es un stub que siempre retorna 0 hasta que exista el modelo Program.');
    }
}
