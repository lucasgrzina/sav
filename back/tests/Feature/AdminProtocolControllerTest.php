<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Protocol;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use App\Models\Vet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminProtocolControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private Technique $root;
    private Technique $subTechnique;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = ['protocols.read', 'protocols.create', 'protocols.update', 'protocols.delete'];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $role = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => 'platform'],
        );
        $role->syncPermissions(Permission::all());

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super-admin');

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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'technique_id' => $this->subTechnique->guid,
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

    // -------------------------------------------------------------------------
    // store
    // -------------------------------------------------------------------------

    public function test_store_creates_protocol_with_nested_tasks_and_alerts(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Protocolo IATF estándar')
            ->assertJsonPath('data.is_global', true)
            ->assertJsonPath('data.created_by_type', 'superadmin')
            ->assertJsonCount(1, 'data.tasks')
            ->assertJsonCount(1, 'data.tasks.0.alerts');

        $this->assertDatabaseHas('protocols', ['name' => 'Protocolo IATF estándar']);
    }

    public function test_store_fails_with_duplicate_name(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')->postJson('/api/v1/admin/protocols', $this->basePayload());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload());

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_store_fails_when_technique_id_is_root(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload(['technique_id' => $this->root->guid]));

        $response->assertStatus(422)->assertJsonValidationErrors('technique_id');
    }

    public function test_store_fails_when_alert_has_no_roles(): void
    {
        $payload = $this->basePayload();
        $payload['tasks'][0]['alerts'][0]['roles'] = [];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors('tasks.0.alerts.0.roles');
    }

    public function test_store_requires_permission(): void
    {
        $userWithoutPermission = User::factory()->create();

        $response = $this->actingAs($userWithoutPermission, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload());

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // replicate
    // -------------------------------------------------------------------------

    public function test_replicate_creates_clone_with_copia_suffix(): void
    {
        $original = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload())
            ->json('data');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/admin/protocols/{$original['guid']}/replicate");

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Protocolo IATF estándar (copia)')
            ->assertJsonCount(1, 'data.tasks')
            ->assertJsonCount(1, 'data.tasks.0.alerts');

        $clone = $response->json('data');

        $this->assertNotEquals($original['guid'], $clone['guid']);
        $this->assertNotEquals($original['tasks'][0]['guid'], $clone['tasks'][0]['guid']);
        $this->assertNotEquals($original['tasks'][0]['alerts'][0]['guid'], $clone['tasks'][0]['alerts'][0]['guid']);

        $this->assertEquals($original['tasks'][0]['days_offset'], $clone['tasks'][0]['days_offset']);
        $this->assertEquals($original['tasks'][0]['time_of_day'], $clone['tasks'][0]['time_of_day']);
        $this->assertEquals($original['tasks'][0]['important'], $clone['tasks'][0]['important']);
        $this->assertEquals($original['tasks'][0]['alerts'][0]['roles'], $clone['tasks'][0]['alerts'][0]['roles']);
        $this->assertEquals($original['tasks'][0]['alerts'][0]['message'], $clone['tasks'][0]['alerts'][0]['message']);
        $this->assertEquals(
            $original['tasks'][0]['alerts'][0]['require_confirmation'],
            $clone['tasks'][0]['alerts'][0]['require_confirmation'],
        );

        $this->assertDatabaseHas('protocols', ['name' => 'Protocolo IATF estándar (copia)']);
    }

    public function test_replicate_keeps_same_technique_and_country(): void
    {
        $original = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload())
            ->json('data');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/admin/protocols/{$original['guid']}/replicate");

        $response->assertJsonPath('data.technique.guid', $original['technique']['guid']);
        $this->assertEquals($original['is_global'], $response->json('data.is_global'));
    }

    public function test_replicate_sets_created_by_to_authenticated_user(): void
    {
        $original = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload())
            ->json('data');

        $otherSuperAdmin = User::factory()->create();
        $otherSuperAdmin->assignRole('super-admin');

        $response = $this->actingAs($otherSuperAdmin, 'sanctum')
            ->postJson("/api/v1/admin/protocols/{$original['guid']}/replicate");

        $response->assertJsonPath('data.created_by_type', 'superadmin');
        $this->assertDatabaseHas('protocols', [
            'name'             => 'Protocolo IATF estándar (copia)',
            'created_by_type'  => 'superadmin',
            'created_by_id'    => $otherSuperAdmin->id,
        ]);
    }

    public function test_replicate_returns_404_for_nonexistent_guid(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols/' . Str::uuid()->toString() . '/replicate');

        $response->assertStatus(404);
    }

    public function test_replicate_requires_permission(): void
    {
        $original = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload())
            ->json('data');

        $userWithoutPermission = User::factory()->create();

        $response = $this->actingAs($userWithoutPermission, 'sanctum')
            ->postJson("/api/v1/admin/protocols/{$original['guid']}/replicate");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // simulate
    // -------------------------------------------------------------------------

    public function test_simulate_returns_computed_schedule(): void
    {
        $original = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload([
                'tasks' => [
                    ['description' => 'Tarea before', 'days_offset' => 3, 'time_of_day' => 'before', 'time' => '08:00', 'important' => true, 'alerts' => [
                        ['offset_days' => 1, 'time_of_day' => 'after', 'time' => '09:00', 'roles' => ['vet'], 'message' => 'Alerta 1', 'require_confirmation' => true],
                    ]],
                    ['description' => 'Tarea after', 'days_offset' => 2, 'time_of_day' => 'after', 'time' => '10:00', 'important' => false, 'alerts' => [
                        ['offset_days' => 1, 'time_of_day' => 'before', 'time' => '11:00', 'roles' => ['client-owner'], 'message' => 'Alerta 2', 'require_confirmation' => false],
                    ]],
                ],
            ]))
            ->json('data');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/protocols/{$original['guid']}/simulate?base_date=2026-08-15");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.base_date', '2026-08-15')
            ->assertJsonCount(2, 'data.tasks')
            ->assertJsonCount(2, 'data.alerts');

        $data = $response->json('data');

        // Tarea "before" -3 días: 2026-08-12. Tarea "after" +2 días: 2026-08-17.
        $this->assertEquals('Tarea before', $data['tasks'][0]['description']);
        $this->assertEquals('2026-08-12', $data['tasks'][0]['computed_date']);
        $this->assertEquals('Tarea after', $data['tasks'][1]['description']);
        $this->assertEquals('2026-08-17', $data['tasks'][1]['computed_date']);

        // Alerta de la primera tarea: tarea (12/08) + 1 día = 13/08 (relativa a la tarea, no a base_date).
        $this->assertEquals('2026-08-13', $data['tasks'][0]['alerts'][0]['computed_date']);
    }

    public function test_simulate_does_not_persist_anything(): void
    {
        $original = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload())
            ->json('data');

        $tasksCountBefore  = \App\Models\ProtocolTask::count();
        $alertsCountBefore = \App\Models\ProtocolTaskAlert::count();

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/protocols/{$original['guid']}/simulate?base_date=2026-08-15");

        $this->assertEquals($tasksCountBefore, \App\Models\ProtocolTask::count());
        $this->assertEquals($alertsCountBefore, \App\Models\ProtocolTaskAlert::count());
    }

    public function test_simulate_fails_with_missing_base_date(): void
    {
        $original = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload())
            ->json('data');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/protocols/{$original['guid']}/simulate");

        $response->assertStatus(422)->assertJsonValidationErrors('base_date');
    }

    public function test_simulate_fails_with_invalid_date_format(): void
    {
        $original = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload())
            ->json('data');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/protocols/{$original['guid']}/simulate?base_date=15/08/2026");

        $response->assertStatus(422)->assertJsonValidationErrors('base_date');
    }

    public function test_simulate_returns_404_for_nonexistent_guid(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/protocols/' . Str::uuid()->toString() . '/simulate?base_date=2026-08-15');

        $response->assertStatus(404);
    }

    public function test_simulate_requires_permission(): void
    {
        $original = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/protocols', $this->basePayload())
            ->json('data');

        $userWithoutPermission = User::factory()->create();

        $response = $this->actingAs($userWithoutPermission, 'sanctum')
            ->getJson("/api/v1/admin/protocols/{$original['guid']}/simulate?base_date=2026-08-15");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    public function test_show_returns_404_for_nonexistent_guid(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/protocols/' . Str::uuid()->toString());

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    public function test_index_requires_root_guid(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/protocols');

        $response->assertStatus(422);
    }

    public function test_index_lists_protocols_grouped_by_root(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')->postJson('/api/v1/admin/protocols', $this->basePayload());

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/protocols?root_guid={$this->root->guid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 1);
    }

    /**
     * Regresión DEC-08: el listado admin (capa plantilla) NUNCA debe incluir protocolos
     * con vet_id seteado, aunque compartan sub-técnica con un protocolo global.
     */
    public function test_index_excludes_protocols_owned_by_a_vet(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')->postJson('/api/v1/admin/protocols', $this->basePayload());

        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'AR',
            'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid'             => Str::uuid()->toString(),
            'country_id'       => $country->id,
            'name'             => 'CUIT',
            'validation_regex' => '.*',
        ]);
        $vet = Vet::create([
            'guid'             => Str::uuid()->toString(),
            'name'             => 'Vet Test',
            'slug'             => 'vet-test',
            'country_id'       => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id'           => '20-12345678-9',
            'validated_at'     => now(),
        ]);

        Protocol::create([
            'guid'            => Str::uuid()->toString(),
            'technique_id'    => $this->subTechnique->id,
            'vet_id'          => $vet->id,
            'created_by_type' => 'vet',
            'created_by_id'   => 1,
            'name'            => 'Protocolo de vet',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/protocols?root_guid={$this->root->guid}");

        $response->assertStatus(200)->assertJsonPath('data.total', 1);
        $this->assertEquals('Protocolo IATF estándar', $response->json('data.data.0.name'));
    }
}
