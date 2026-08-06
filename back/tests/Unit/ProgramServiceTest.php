<?php

namespace Tests\Unit;

use App\Exceptions\ProgramMustHaveOneTargetException;
use App\Exceptions\ProgramNotEditableException;
use App\Models\Animal;
use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Program;
use App\Models\Protocol;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Repositories\AnimalRepositoryEloquent;
use App\Repositories\ProgramRepositoryEloquent;
use App\Services\ProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProgramService $service;
    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private Protocol $protocol;
    private Technique $technique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProgramService(new ProgramRepositoryEloquent(), new AnimalRepositoryEloquent());

        $this->vet = $this->createVet();
        $this->client = $this->createClient('Cliente Test');
        $this->establishment = Establishment::create([
            'guid'      => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name'      => 'Establecimiento Test',
        ]);

        $root = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $this->technique = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'IATF',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        $this->protocol = Protocol::create([
            'guid'            => Str::uuid()->toString(),
            'technique_id'    => $this->technique->id,
            'vet_id'          => $this->vet->id,
            'created_by_type' => 'vet',
            'created_by_id'   => 1,
            'name'            => 'Protocolo Test',
        ]);
    }

    private function createVet(string $name = 'Vet Test'): Vet
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'A' . Str::random(2),
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

    private function createClient(string $name): Client
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'A' . Str::random(2),
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid'             => Str::uuid()->toString(),
            'country_id'       => $country->id,
            'name'             => 'CUIT',
            'validation_regex' => '.*',
        ]);

        return Client::create([
            'guid'             => Str::uuid()->toString(),
            'name'             => $name,
            'country_id'       => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id'           => '20-' . Str::random(8) . '-9',
        ]);
    }

    private function createManagerProfile(Vet $vet): UserProfile
    {
        $role = Role::create(['name' => 'vet-manager-' . Str::random(6), 'guard_name' => 'web']);
        $user = User::factory()->create(['guid' => Str::uuid()->toString()]);

        return UserProfile::create([
            'guid'                  => Str::uuid()->toString(),
            'user_id'               => $user->id,
            'authenticatable_type'  => 'vet',
            'authenticatable_id'    => $vet->id,
            'role_id'               => $role->id,
        ]);
    }

    private function createManagerProfileWithRole(Vet $vet, string $roleName): UserProfile
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::factory()->create(['guid' => Str::uuid()->toString()]);

        return UserProfile::create([
            'guid'                 => Str::uuid()->toString(),
            'user_id'              => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id'   => $vet->id,
            'role_id'              => $role->id,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'client_id'        => $this->client->id,
            'establishment_id' => $this->establishment->id,
            'protocol_id'      => $this->protocol->id,
            'comments'         => null,
            'targets'          => [
                ['target_date' => '2026-08-01', 'animals' => []],
            ],
            'manager_profile_ids' => [$this->createManagerProfile($this->vet)->id],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // create — caso simple N=1
    // -------------------------------------------------------------------------

    public function test_create_with_one_target_without_animals(): void
    {
        $program = $this->service->create($this->basePayload(), $this->vet->id);

        $this->assertEquals($this->vet->id, $program->vet_id);
        $this->assertEquals($this->technique->id, $program->technique_id); // DEC-06: siempre desde protocol
        $this->assertCount(1, $program->targets);
        $this->assertCount(0, $program->targets->first()->animals);
    }

    // -------------------------------------------------------------------------
    // create — N targets con animales nuevos y existentes mezclados
    // -------------------------------------------------------------------------

    public function test_create_with_multiple_targets_mixing_new_and_existing_animals(): void
    {
        $existingAnimal = Animal::create(['guid' => Str::uuid()->toString(), 'client_id' => $this->client->id, 'rp' => 'EXIST01', 'type' => 'livestock']);

        $program = $this->service->create($this->basePayload([
            'targets' => [
                [
                    'target_date' => '2026-08-01',
                    'animals'     => [
                        ['rp' => 'NEW01'],
                        ['id' => $existingAnimal->guid, 'rp' => 'EXIST01'],
                    ],
                ],
                [
                    'target_date' => '2026-09-01',
                    'animals'     => [
                        ['rp' => 'NEW02'],
                    ],
                ],
            ],
        ]), $this->vet->id);

        $this->assertCount(2, $program->targets);

        $target1 = $program->targets->first(fn ($t) => $t->target_date->toDateString() === '2026-08-01');
        $this->assertEqualsCanonicalizing(['NEW01', 'EXIST01'], $target1->animals->pluck('rp')->all());

        $target2 = $program->targets->first(fn ($t) => $t->target_date->toDateString() === '2026-09-01');
        $this->assertEqualsCanonicalizing(['NEW02'], $target2->animals->pluck('rp')->all());
    }

    // -------------------------------------------------------------------------
    // update — agrega/quita targets
    // -------------------------------------------------------------------------

    public function test_update_adds_and_removes_targets(): void
    {
        $program = $this->service->create($this->basePayload([
            'targets' => [
                ['target_date' => '2026-08-01', 'animals' => []],
                ['target_date' => '2026-08-15', 'animals' => []],
            ],
        ]), $this->vet->id);

        $keptGuid = $program->targets->first(fn ($t) => $t->target_date->toDateString() === '2026-08-01')->guid;
        $removedGuid = $program->targets->first(fn ($t) => $t->target_date->toDateString() === '2026-08-15')->guid;

        $updated = $this->service->update($program, $this->basePayload([
            'targets' => [
                ['guid' => $keptGuid, 'target_date' => '2026-08-01', 'animals' => []],
                ['target_date' => '2026-09-01', 'animals' => []],
            ],
        ]));

        $dates = $updated->targets->pluck('target_date')->map(fn ($d) => $d->toDateString())->all();
        $this->assertEqualsCanonicalizing(['2026-08-01', '2026-09-01'], $dates);
        $this->assertDatabaseMissing('program_targets', ['guid' => $removedGuid]);
    }

    // -------------------------------------------------------------------------
    // update — dejar 0 targets debe lanzar excepción, sin tocar la base
    // -------------------------------------------------------------------------

    public function test_update_throws_exception_when_it_would_leave_zero_targets(): void
    {
        $program = $this->service->create($this->basePayload(), $this->vet->id);

        $this->expectException(ProgramMustHaveOneTargetException::class);

        $this->service->update($program, $this->basePayload(['targets' => []]));
    }

    public function test_update_does_not_touch_database_when_it_would_leave_zero_targets(): void
    {
        $program = $this->service->create($this->basePayload(), $this->vet->id);
        $targetCountBefore = $program->targets()->count();

        try {
            $this->service->update($program, $this->basePayload(['targets' => []]));
        } catch (ProgramMustHaveOneTargetException) {
            // esperado
        }

        $this->assertEquals($targetCountBefore, $program->targets()->count());
    }

    // -------------------------------------------------------------------------
    // update — sobre programa cancelado debe lanzar excepción
    // -------------------------------------------------------------------------

    public function test_update_on_cancelled_program_throws_exception(): void
    {
        $program = $this->service->create($this->basePayload(), $this->vet->id);
        $this->service->cancel($program);

        $this->expectException(ProgramNotEditableException::class);

        $this->service->update($program->fresh(), $this->basePayload());
    }

    // -------------------------------------------------------------------------
    // cancel
    // -------------------------------------------------------------------------

    public function test_cancel_marks_cancelled_at(): void
    {
        $program = $this->service->create($this->basePayload(), $this->vet->id);

        $cancelled = $this->service->cancel($program);

        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertFalse($cancelled->fresh()->editable);
    }

    public function test_cancel_on_already_cancelled_program_throws_exception(): void
    {
        $program = $this->service->create($this->basePayload(), $this->vet->id);
        $this->service->cancel($program);

        $this->expectException(ProgramNotEditableException::class);

        $this->service->cancel($program->fresh());
    }

    // -------------------------------------------------------------------------
    // managers — sync (reemplazo total, DEC-08)
    // -------------------------------------------------------------------------

    public function test_managers_are_synced_with_full_replacement(): void
    {
        $managerA = $this->createManagerProfile($this->vet);
        $managerB = $this->createManagerProfile($this->vet);

        $program = $this->service->create($this->basePayload(['manager_profile_ids' => [$managerA->id]]), $this->vet->id);
        $this->assertEqualsCanonicalizing([$managerA->id], $program->managers->pluck('id')->all());

        $updated = $this->service->update($program, $this->basePayload(['manager_profile_ids' => [$managerB->id]]));

        $this->assertEqualsCanonicalizing([$managerB->id], $updated->managers->pluck('id')->all());
    }

    // -------------------------------------------------------------------------
    // DEC-07 regla 2 — animal id que no pertenece al client_id del programa
    // -------------------------------------------------------------------------

    public function test_animal_id_not_belonging_to_program_client_is_silently_discarded(): void
    {
        $otherClient = $this->createClient('Otro cliente');
        $foreignAnimal = Animal::create(['guid' => Str::uuid()->toString(), 'client_id' => $otherClient->id, 'rp' => 'FOREIGN', 'type' => 'livestock']);

        $program = $this->service->create($this->basePayload([
            'targets' => [
                [
                    'target_date' => '2026-08-01',
                    'animals'     => [
                        ['id' => $foreignAnimal->guid, 'rp' => 'FOREIGN'],
                        ['rp' => 'VALID'],
                    ],
                ],
            ],
        ]), $this->vet->id);

        $target = $program->targets->first();
        $this->assertEqualsCanonicalizing(['VALID'], $target->animals->pluck('rp')->all());
    }

    // -------------------------------------------------------------------------
    // DEC-07 regla 1 — rp nuevo que ya existe para el MISMO cliente reutiliza la fila
    // -------------------------------------------------------------------------

    public function test_new_rp_reuses_existing_animal_of_same_client(): void
    {
        $existing = Animal::create(['guid' => Str::uuid()->toString(), 'client_id' => $this->client->id, 'rp' => 'DUP01', 'type' => 'livestock']);

        $program = $this->service->create($this->basePayload([
            'targets' => [
                ['target_date' => '2026-08-01', 'animals' => [['rp' => 'DUP01']]],
            ],
        ]), $this->vet->id);

        $target = $program->targets->first();
        $this->assertCount(1, $target->animals);
        $this->assertEquals($existing->id, $target->animals->first()->id);
        $this->assertEquals(1, Animal::where('client_id', $this->client->id)->where('rp', 'DUP01')->count());
    }

    // -------------------------------------------------------------------------
    // DEC-07 regla 3 — rp coincidente con OTRO cliente crea fila nueva propia
    // -------------------------------------------------------------------------

    public function test_new_rp_matching_another_client_creates_new_row_for_this_client(): void
    {
        $otherClient = $this->createClient('Otro cliente');
        $otherAnimal = Animal::create(['guid' => Str::uuid()->toString(), 'client_id' => $otherClient->id, 'rp' => 'SHARED', 'type' => 'livestock']);

        $program = $this->service->create($this->basePayload([
            'targets' => [
                ['target_date' => '2026-08-01', 'animals' => [['rp' => 'SHARED']]],
            ],
        ]), $this->vet->id);

        $target = $program->targets->first();
        $createdAnimal = $target->animals->first();

        $this->assertNotEquals($otherAnimal->id, $createdAnimal->id);
        $this->assertEquals($this->client->id, $createdAnimal->client_id);
        $this->assertEquals('SHARED', $createdAnimal->rp);
    }

    // -------------------------------------------------------------------------
    // DEC-15 — proyección de tareas/alertas en el detalle
    // -------------------------------------------------------------------------

    public function test_project_target_tasks_calcula_fechas_con_offset_before_y_after(): void
    {
        $taskBefore = ProtocolTask::create([
            'guid'        => Str::uuid()->toString(),
            'protocol_id' => $this->protocol->id,
            'description' => 'Tarea antes',
            'days_offset' => 3,
            'time_of_day' => 'before',
            'time'        => '08:00',
            'important'   => false,
            'sort_order'  => 1,
        ]);

        $taskAfter = ProtocolTask::create([
            'guid'        => Str::uuid()->toString(),
            'protocol_id' => $this->protocol->id,
            'description' => 'Tarea despues',
            'days_offset' => 5,
            'time_of_day' => 'after',
            'time'        => '09:00',
            'important'   => false,
            'sort_order'  => 2,
        ]);

        ProtocolTaskAlert::create([
            'guid'                  => Str::uuid()->toString(),
            'protocol_task_id'      => $taskBefore->id,
            'offset_days'           => 1,
            'time_of_day'           => 'after',
            'time'                  => '08:30',
            'roles'                 => ['vet'],
            'message'               => 'Alerta A',
            'require_confirmation'  => false,
            'sort_order'            => 1,
        ]);

        $program = $this->service->create($this->basePayload([
            'targets' => [['target_date' => '2026-08-15', 'animals' => []]],
        ]), $this->vet->id);

        $detail = $this->service->findByGuidForVet($program->guid, $this->vet->id);
        $target = $detail->targets->first();

        $tasksByGuid = collect($target->projected_tasks)->keyBy('protocol_task_guid');

        $this->assertEquals('2026-08-12', $tasksByGuid[$taskBefore->guid]['occurs_on']);
        $this->assertEquals('2026-08-20', $tasksByGuid[$taskAfter->guid]['occurs_on']);
        $this->assertEquals('2026-08-13', $tasksByGuid[$taskBefore->guid]['alerts'][0]['occurs_on']);
    }

    public function test_project_target_tasks_mapea_recipients_por_rol(): void
    {
        $vetAssistant = $this->createManagerProfileWithRole($this->vet, 'vet-assistant');
        $clientOwner  = $this->createManagerProfileWithRole($this->vet, 'client-owner');

        $task = ProtocolTask::create([
            'guid'        => Str::uuid()->toString(),
            'protocol_id' => $this->protocol->id,
            'description' => 'Tarea',
            'days_offset' => 0,
            'time_of_day' => 'after',
            'time'        => '08:00',
            'important'   => false,
            'sort_order'  => 1,
        ]);

        ProtocolTaskAlert::create([
            'guid'                 => Str::uuid()->toString(),
            'protocol_task_id'     => $task->id,
            'offset_days'          => 0,
            'time_of_day'          => 'after',
            'time'                 => '08:00',
            'roles'                => ['vet-assistant'],
            'message'              => 'Solo vet-assistant',
            'require_confirmation' => false,
            'sort_order'           => 1,
        ]);

        $program = $this->service->create($this->basePayload([
            'targets'             => [['target_date' => '2026-08-15', 'animals' => []]],
            'manager_profile_ids' => [$vetAssistant->id, $clientOwner->id],
        ]), $this->vet->id);

        $detail = $this->service->findByGuidForVet($program->guid, $this->vet->id);
        $alert  = $detail->targets->first()->projected_tasks[0]['alerts'][0];

        $recipientRoles = collect($alert['recipients'])->pluck('role')->all();

        $this->assertEqualsCanonicalizing(['vet-assistant'], $recipientRoles);
    }
}
