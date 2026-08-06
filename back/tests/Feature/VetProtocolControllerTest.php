<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Protocol;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VetProtocolControllerTest extends TestCase
{
    use RefreshDatabase;

    private Technique $root;
    private Technique $subTechnique;
    private Vet $vet;

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

        foreach (['vet', 'vet-assistant', 'vet-administrative'] as $roleName) {
            Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
            );
        }

        // DEC-10: vet, vet-administrative y vet-assistant reciben el set completo.
        Role::where('name', 'vet')->first()->givePermissionTo(Permission::whereIn('name', $permissions)->get());
        Role::where('name', 'vet-administrative')->first()->givePermissionTo(Permission::whereIn('name', $permissions)->get());
        Role::where('name', 'vet-assistant')->first()->givePermissionTo(Permission::whereIn('name', $permissions)->get());

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

        $this->vet = $this->createVet();
    }

    private function createVet(string $name = 'Vet Test'): Vet
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina ' . $name,
            'iso_code'     => strtoupper(Str::random(2)),
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

    private function createUserForVet(Vet $vet, string $roleName = 'vet'): User
    {
        $user = User::factory()->create();
        $role = Role::where('name', $roleName)->first();

        UserProfile::create([
            'guid'                 => Str::uuid()->toString(),
            'user_id'              => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id'   => $vet->id,
            'role_id'              => $role->id,
        ]);

        return $user;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'technique_id' => $this->subTechnique->guid,
            'name'         => 'Protocolo propio IATF',
            'color'        => '#3B82F6',
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
    // index
    // -------------------------------------------------------------------------

    public function test_index_mixes_globals_and_own(): void
    {
        $user = $this->createUserForVet($this->vet);

        Protocol::create([
            'guid'            => Str::uuid()->toString(),
            'technique_id'    => $this->subTechnique->id,
            'created_by_type' => 'superadmin',
            'created_by_id'   => 1,
            'name'            => 'Protocolo global',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/protocols", $this->basePayload());

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/protocols");

        $response->assertStatus(200)->assertJsonPath('data.total', 2);
    }

    // -------------------------------------------------------------------------
    // store
    // -------------------------------------------------------------------------

    public function test_store_creates_protocol_owned_by_vet(): void
    {
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/protocols", $this->basePayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.is_global', false)
            ->assertJsonPath('data.is_own', true);

        $this->assertDatabaseHas('protocols', ['name' => 'Protocolo propio IATF', 'vet_id' => $this->vet->id]);
    }

    public function test_store_fails_without_permission(): void
    {
        $user = $this->createUserForVet($this->vet, 'vet-assistant');
        // vet-assistant tiene set completo (DEC-10) — probamos con un usuario sin perfil en la vet.
        $userWithoutProfile = User::factory()->create();

        $response = $this->actingAs($userWithoutProfile, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/protocols", $this->basePayload());

        $response->assertStatus(403);
    }

    public function test_store_allows_vet_assistant_with_full_permission_set(): void
    {
        $user = $this->createUserForVet($this->vet, 'vet-assistant');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/protocols", $this->basePayload());

        $response->assertStatus(201);
    }

    public function test_store_fails_with_duplicate_name_within_same_vet(): void
    {
        $user = $this->createUserForVet($this->vet);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/vets/{$this->vet->guid}/protocols", $this->basePayload());
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/vets/{$this->vet->guid}/protocols", $this->basePayload());

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_store_allows_same_name_across_different_vets(): void
    {
        $otherVet = $this->createVet('Otra Vet');
        $userA = $this->createUserForVet($this->vet);
        $userB = $this->createUserForVet($otherVet);

        $responseA = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/protocols", $this->basePayload());
        $responseB = $this->actingAs($userB, 'sanctum')
            ->postJson("/api/v1/vets/{$otherVet->guid}/protocols", $this->basePayload());

        $responseA->assertStatus(201);
        $responseB->assertStatus(201);
    }

    // -------------------------------------------------------------------------
    // update / destroy — ownership (DEC-04)
    // -------------------------------------------------------------------------

    public function test_update_returns_404_for_global_protocol(): void
    {
        $user = $this->createUserForVet($this->vet);

        $global = Protocol::create([
            'guid'            => Str::uuid()->toString(),
            'technique_id'    => $this->subTechnique->id,
            'created_by_type' => 'superadmin',
            'created_by_id'   => 1,
            'name'            => 'Protocolo global',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/protocols/{$global->guid}", $this->basePayload(['name' => 'Editado']));

        $response->assertStatus(404);
    }

    public function test_update_returns_404_for_protocol_of_another_vet(): void
    {
        $otherVet = $this->createVet('Otra Vet');
        $userA = $this->createUserForVet($this->vet);
        $userB = $this->createUserForVet($otherVet);

        $ownProtocol = $this->actingAs($userB, 'sanctum')
            ->postJson("/api/v1/vets/{$otherVet->guid}/protocols", $this->basePayload())
            ->json('data');

        $response = $this->actingAs($userA, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/protocols/{$ownProtocol['guid']}", $this->basePayload(['name' => 'Editado']));

        $response->assertStatus(404);
    }

    public function test_destroy_returns_404_for_global_protocol(): void
    {
        $user = $this->createUserForVet($this->vet);

        $global = Protocol::create([
            'guid'            => Str::uuid()->toString(),
            'technique_id'    => $this->subTechnique->id,
            'created_by_type' => 'superadmin',
            'created_by_id'   => 1,
            'name'            => 'Protocolo global',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/vets/{$this->vet->guid}/protocols/{$global->guid}");

        $response->assertStatus(404);
    }

    public function test_update_edits_own_protocol(): void
    {
        $user = $this->createUserForVet($this->vet);

        $own = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/protocols", $this->basePayload())
            ->json('data');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/protocols/{$own['guid']}", $this->basePayload(['name' => 'Protocolo editado']));

        $response->assertStatus(200)->assertJsonPath('data.name', 'Protocolo editado');
    }

    public function test_destroy_deletes_own_protocol(): void
    {
        $user = $this->createUserForVet($this->vet);

        $own = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/protocols", $this->basePayload())
            ->json('data');

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/vets/{$this->vet->guid}/protocols/{$own['guid']}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('protocols', ['guid' => $own['guid']]);
    }
}
