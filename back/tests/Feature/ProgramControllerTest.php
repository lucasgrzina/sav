<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
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
use Tests\Concerns\UniqueIsoCode;

class ProgramControllerTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private Protocol $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = ['programs.read', 'programs.create', 'programs.update'];
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
        Role::firstOrCreate(
            ['name' => 'client-owner', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );

        Role::where('name', 'vet')->first()->givePermissionTo(Permission::whereIn('name', $permissions)->get());

        $this->vet = $this->createVet();
        $this->client = $this->createClient('Cliente Test');
        $this->vet->clients()->attach($this->client->id, ['created_at' => now(), 'updated_at' => now()]);
        $this->establishment = Establishment::create([
            'guid'      => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name'      => 'Establecimiento Test',
        ]);

        $root = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $technique = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'IATF',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        $this->protocol = Protocol::create([
            'guid'            => Str::uuid()->toString(),
            'technique_id'    => $technique->id,
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
            'name'         => 'Argentina ' . $name,
            'iso_code'     => UniqueIsoCode::next(),
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
            'name'         => 'Argentina ' . $name,
            'iso_code'     => UniqueIsoCode::next(),
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

    private function createManagerProfileForVet(Vet $vet): UserProfile
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'vet')->first();

        return UserProfile::create([
            'guid'                 => Str::uuid()->toString(),
            'user_id'              => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id'   => $vet->id,
            'role_id'              => $role->id,
        ]);
    }

    private function createManagerProfileForClient(Client $client, bool $link = true): UserProfile
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'client-owner')->first();

        $profile = UserProfile::create([
            'guid'                 => Str::uuid()->toString(),
            'user_id'              => $user->id,
            'authenticatable_type' => 'client',
            'authenticatable_id'   => $client->id,
            'role_id'              => $role->id,
        ]);

        // Client staff can only manage programs of establishments they are linked to.
        if ($link && $client->id === $this->establishment->client_id) {
            $this->establishment->staff()->attach($profile->id);
        }

        return $profile;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'client_id'        => $this->client->guid,
            'establishment_id' => $this->establishment->guid,
            'protocol_id'      => $this->protocol->guid,
            'comments'         => null,
            'targets'          => [
                ['target_date' => '2026-08-01', 'animals' => []],
            ],
            'manager_profile_ids' => [$this->createManagerProfileForVet($this->vet)->guid],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // DEC-12 — manager_profile_ids acepta staff de vet O de cliente
    // -------------------------------------------------------------------------

    public function test_store_accepts_manager_profile_from_client_of_the_program(): void
    {
        $user = $this->createUserForVet($this->vet);
        $clientManager = $this->createManagerProfileForClient($this->client);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'manager_profile_ids' => [$clientManager->guid],
            ]));

        $response->assertStatus(201);
        $this->assertEquals('client', $response->json('data.managers.0.origin'));
    }

    public function test_store_rejects_manager_profile_from_other_vet(): void
    {
        $user = $this->createUserForVet($this->vet);
        $otherVet = $this->createVet('Otra Vet');
        $foreignManager = $this->createManagerProfileForVet($otherVet);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'manager_profile_ids' => [$foreignManager->guid],
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['manager_profile_ids.0']);
    }

    public function test_store_rejects_manager_profile_from_other_client(): void
    {
        $user = $this->createUserForVet($this->vet);
        $otherClient = $this->createClient('Otro cliente');
        $foreignClientManager = $this->createManagerProfileForClient($otherClient);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'manager_profile_ids' => [$foreignClientManager->guid],
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['manager_profile_ids.0']);
    }

    // -------------------------------------------------------------------------
    // show — proyección de tareas/alertas (DEC-15/DEC-16) presente en el detalle
    // -------------------------------------------------------------------------

    public function test_show_returns_managers_with_origin(): void
    {
        $user = $this->createUserForVet($this->vet);
        $vetManager = $this->createManagerProfileForVet($this->vet);

        $store = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'manager_profile_ids' => [$vetManager->guid],
            ]));

        $guid = $store->json('data.guid');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}");

        $response->assertStatus(200)->assertJsonPath('data.managers.0.origin', 'vet');
    }

    // -------------------------------------------------------------------------
    // Client staff <-> establishment link: client managers must be linked
    // -------------------------------------------------------------------------

    private function createSecondEstablishment(): Establishment
    {
        return Establishment::create([
            'guid'      => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name'      => 'Segundo Establecimiento',
        ]);
    }

    public function test_store_rejects_unlinked_client_manager_with_error_per_index(): void
    {
        $user = $this->createUserForVet($this->vet);
        $vetManager = $this->createManagerProfileForVet($this->vet);
        $unlinked = $this->createManagerProfileForClient($this->client, false);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'manager_profile_ids' => [$vetManager->guid, $unlinked->guid],
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['manager_profile_ids.1']);
        $this->assertArrayNotHasKey('manager_profile_ids.0', $response->json('errors') ?? []);
    }

    public function test_store_accepts_vet_manager_without_any_link(): void
    {
        $user = $this->createUserForVet($this->vet);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload())
            ->assertStatus(201);
    }

    public function test_update_accepts_linked_client_manager(): void
    {
        $user = $this->createUserForVet($this->vet);
        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload());
        $guid = $created->json('data.guid');
        $linked = $this->createManagerProfileForClient($this->client);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}", $this->basePayload([
                'targets' => [['guid' => $created->json('data.targets.0.guid'), 'target_date' => '2026-08-01', 'animals' => []]],
                'manager_profile_ids' => [$linked->guid],
            ]))
            ->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Group name (program_targets.name) — optional, feeds the [grupo] placeholder
    // -------------------------------------------------------------------------

    public function test_group_name_is_stored_trimmed_exposed_in_the_resource_and_updatable(): void
    {
        $user = $this->createUserForVet($this->vet);
        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'targets' => [
                    ['target_date' => '2026-08-01', 'name' => '  Lote Norte  ', 'animals' => []],
                    ['target_date' => '2026-08-02', 'name' => '', 'animals' => []],
                    ['target_date' => '2026-08-03', 'animals' => []],
                ],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.targets.0.name', 'Lote Norte')
            ->assertJsonPath('data.targets.1.name', null)
            ->assertJsonPath('data.targets.2.name', null);

        $guid = $created->json('data.guid');
        $first = $created->json('data.targets.0.guid');

        // Rename one group; a payload without the key leaves the stored name untouched.
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}", $this->basePayload([
                'targets' => [['guid' => $first, 'target_date' => '2026-08-01', 'name' => 'Lote Sur', 'animals' => []]],
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.targets.0.name', 'Lote Sur');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}", $this->basePayload([
                'targets' => [['guid' => $first, 'target_date' => '2026-08-05', 'animals' => []]],
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.targets.0.name', 'Lote Sur');
    }

    public function test_group_name_over_100_characters_is_rejected_on_store_and_update(): void
    {
        $user = $this->createUserForVet($this->vet);
        $tooLong = str_repeat('a', 101);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'targets' => [['target_date' => '2026-08-01', 'name' => $tooLong, 'animals' => []]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['targets.0.name']);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload());

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/programs/{$created->json('data.guid')}", $this->basePayload([
                'targets' => [['guid' => $created->json('data.targets.0.guid'), 'target_date' => '2026-08-01', 'name' => $tooLong, 'animals' => []]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['targets.0.name']);
    }

    public function test_program_targets_table_has_a_nullable_name_column(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('program_targets', 'name'));
        $column = collect(\Illuminate\Support\Facades\Schema::getColumns('program_targets'))->firstWhere('name', 'name');
        $this->assertTrue($column['nullable']);
    }

    public function test_update_rejects_unlinked_client_manager(): void
    {
        $user = $this->createUserForVet($this->vet);
        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload());
        $guid = $created->json('data.guid');
        $unlinked = $this->createManagerProfileForClient($this->client, false);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}", $this->basePayload([
                'targets' => [['guid' => $created->json('data.targets.0.guid'), 'target_date' => '2026-08-01', 'animals' => []]],
                'manager_profile_ids' => [$unlinked->guid],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['manager_profile_ids.0']);
    }

    public function test_update_changing_establishment_to_one_where_manager_is_not_linked_returns_422(): void
    {
        $user = $this->createUserForVet($this->vet);
        $linked = $this->createManagerProfileForClient($this->client); // linked to the first establishment only
        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'manager_profile_ids' => [$linked->guid],
            ]));
        $created->assertStatus(201);
        $guid = $created->json('data.guid');
        $other = $this->createSecondEstablishment();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}", $this->basePayload([
                'establishment_id' => $other->guid,
                'targets' => [['guid' => $created->json('data.targets.0.guid'), 'target_date' => '2026-08-01', 'animals' => []]],
                'manager_profile_ids' => [$linked->guid],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['manager_profile_ids.0']);
    }

    // -------------------------------------------------------------------------
    // Edit + save must keep the recipients (no silent loss)
    // -------------------------------------------------------------------------

    public function test_update_with_unchanged_payload_keeps_vet_and_client_managers(): void
    {
        $user = $this->createUserForVet($this->vet);
        $vetManager = $this->createManagerProfileForVet($this->vet);
        $clientManager = $this->createManagerProfileForClient($this->client);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'manager_profile_ids' => [$vetManager->guid, $clientManager->guid],
            ]));
        $created->assertStatus(201);
        $guid = $created->json('data.guid');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}", $this->basePayload([
                'comments' => 'edited',
                'targets' => [['guid' => $created->json('data.targets.0.guid'), 'target_date' => '2026-08-01', 'animals' => []]],
                'manager_profile_ids' => [$vetManager->guid, $clientManager->guid],
            ]));

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            [$vetManager->guid, $clientManager->guid],
            collect($response->json('data.managers'))->pluck('guid')->all(),
        );

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.managers');
    }

    public function test_update_rejects_empty_manager_list_and_keeps_current_managers(): void
    {
        $user = $this->createUserForVet($this->vet);
        $vetManager = $this->createManagerProfileForVet($this->vet);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'manager_profile_ids' => [$vetManager->guid],
            ]));
        $created->assertStatus(201);
        $guid = $created->json('data.guid');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}", $this->basePayload([
                'targets' => [['guid' => $created->json('data.targets.0.guid'), 'target_date' => '2026-08-01', 'animals' => []]],
                'manager_profile_ids' => [],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['manager_profile_ids']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}")
            ->assertJsonPath('data.managers.0.guid', $vetManager->guid);
    }

    public function test_update_rejecting_an_unlinked_manager_does_not_alter_the_saved_managers(): void
    {
        $user = $this->createUserForVet($this->vet);
        $linked = $this->createManagerProfileForClient($this->client);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'manager_profile_ids' => [$linked->guid],
            ]));
        $created->assertStatus(201);
        $guid = $created->json('data.guid');

        // The staff gets unlinked from the establishment directly (legacy / drifted data).
        $this->establishment->staff()->detach($linked->id);
        $program = \App\Models\Program::where('guid', $guid)->first();
        $program->managers()->sync([$linked->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/programs/{$guid}", $this->basePayload([
                'targets' => [['guid' => $created->json('data.targets.0.guid'), 'target_date' => '2026-08-01', 'animals' => []]],
                'manager_profile_ids' => [$linked->guid],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['manager_profile_ids.0']);

        $this->assertSame([$linked->id], $program->managers()->pluck('user_profiles.id')->all());
    }

    // -------------------------------------------------------------------------
    // next_target_date uses the vet's calendar day, not UTC
    // -------------------------------------------------------------------------

    public function test_list_next_target_date_uses_the_vet_timezone(): void
    {
        $this->vet->country->update(['timezone' => 'America/Argentina/Buenos_Aires']);
        $user = $this->createUserForVet($this->vet);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", $this->basePayload([
                'targets' => [
                    ['target_date' => '2026-09-23', 'animals' => []],
                    ['target_date' => '2026-09-30', 'animals' => []],
                ],
            ]));
        $created->assertStatus(201);

        // 01:00 UTC of the 24th is still the 23rd (22:00) in Buenos Aires.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-24 01:00:00', 'UTC'));

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/programs")
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.next_target_date', '2026-09-23');
    }
}
