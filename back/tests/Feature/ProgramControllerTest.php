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

    private function createClient(string $name): Client
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

    private function createManagerProfileForClient(Client $client): UserProfile
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'client-owner')->first();

        return UserProfile::create([
            'guid'                 => Str::uuid()->toString(),
            'user_id'              => $user->id,
            'authenticatable_type' => 'client',
            'authenticatable_id'   => $client->id,
            'role_id'              => $role->id,
        ]);
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
}
