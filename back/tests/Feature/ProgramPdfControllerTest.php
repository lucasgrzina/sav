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

class ProgramPdfControllerTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private Protocol $protocol;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['programs.read', 'programs.create'] as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $vetRole = Role::firstOrCreate(
            ['name' => 'vet', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
        $vetRole->givePermissionTo(['programs.read', 'programs.create']);

        Role::firstOrCreate(
            ['name' => 'client-owner', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );

        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina',
            'iso_code' => 'A' . Str::random(4), 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id,
            'name' => 'CUIT', 'validation_regex' => '.*',
        ]);

        $this->vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet Test',
            'slug' => 'vet-test-' . Str::random(6), 'country_id' => $country->id,
            'document_type_id' => $documentType->id, 'tax_id' => '20-12345678-9', 'validated_at' => now(),
        ]);

        $this->client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente Test',
            'country_id' => $country->id, 'document_type_id' => $documentType->id,
            'tax_id' => '20-98765432-1',
        ]);
        $this->vet->clients()->attach($this->client->id, ['created_at' => now(), 'updated_at' => now()]);

        $this->establishment = Establishment::create([
            'guid' => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name' => 'Establecimiento Test',
        ]);

        $root = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $technique = Technique::create([
            'guid' => Str::uuid()->toString(), 'name' => 'IATF', 'type' => 'technique', 'parent_id' => $root->id,
        ]);

        $this->protocol = Protocol::create([
            'guid' => Str::uuid()->toString(),
            'technique_id' => $technique->id,
            'vet_id' => $this->vet->id,
            'created_by_type' => 'vet',
            'created_by_id' => 1,
            'name' => 'Protocolo Test',
        ]);

        $this->actor = User::factory()->create();
        UserProfile::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $this->actor->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $this->vet->id,
            'role_id' => $vetRole->id,
        ]);
    }

    private function createProgramWithManager(): array
    {
        $clientOwnerRole = Role::where('name', 'client-owner')->first();
        $managerUser = User::factory()->create();
        $manager = UserProfile::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $managerUser->id,
            'authenticatable_type' => 'client',
            'authenticatable_id' => $this->client->id,
            'role_id' => $clientOwnerRole->id,
        ]);

        $response = $this->actingAs($this->actor, 'sanctum')->postJson(
            "/api/v1/vets/{$this->vet->guid}/programs",
            [
                'client_id' => $this->client->guid,
                'establishment_id' => $this->establishment->guid,
                'protocol_id' => $this->protocol->guid,
                'comments' => null,
                'targets' => [['target_date' => '2026-08-01', 'animals' => []]],
                'manager_profile_ids' => [$manager->guid],
            ],
        );

        $programGuid = $response->json('data.guid');

        return [$programGuid, $manager];
    }

    public function test_share_rechaza_guid_que_no_pertenece_al_programa(): void
    {
        [$programGuid] = $this->createProgramWithManager();

        // Un UserProfile del cliente que NO fue asignado como manager de este programa.
        $clientOwnerRole = Role::where('name', 'client-owner')->first();
        $strangerUser = User::factory()->create();
        $stranger = UserProfile::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $strangerUser->id,
            'authenticatable_type' => 'client',
            'authenticatable_id' => $this->client->id,
            'role_id' => $clientOwnerRole->id,
        ]);

        $response = $this->actingAs($this->actor, 'sanctum')->postJson(
            "/api/v1/vets/{$this->vet->guid}/programs/{$programGuid}/share",
            ['manager_profile_ids' => [$stranger->guid]],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['manager_profile_ids.0']);
    }

    public function test_share_recipients_lista_solo_staff_del_lado_cliente(): void
    {
        [$programGuid] = $this->createProgramWithManager();

        $url = "/api/v1/vets/{$this->vet->guid}/programs/{$programGuid}/share-recipients";

        $response = $this->actingAs($this->actor, 'sanctum')->getJson($url);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('client-owner', $response->json('data.0.role'));
    }
}
