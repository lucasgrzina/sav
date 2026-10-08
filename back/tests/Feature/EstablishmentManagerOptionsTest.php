<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstablishmentManagerOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;
    private DocumentType $documentType;
    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private Role $ownerRole;
    private Role $managerRole;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['programs.read', 'programs.create', 'programs.update', 'programs.managers.read', 'establishments.read', 'clients.staff.read'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['guid' => Str::uuid()->toString()]);
        }

        $this->makeRole('vet')->givePermissionTo(['programs.read', 'programs.create', 'programs.update', 'programs.managers.read', 'establishments.read', 'clients.staff.read']);
        // vet-assistant creates programs but has no clients.staff.read.
        $this->makeRole('vet-assistant')->givePermissionTo(['programs.read', 'programs.create', 'programs.update', 'programs.managers.read', 'establishments.read']);
        // Program write permissions alone are not enough: the dedicated permission is required.
        $this->makeRole('vet-no-managers')->givePermissionTo(['programs.read', 'programs.create', 'programs.update', 'establishments.read']);
        $this->makeRole('vet-viewer')->givePermissionTo(['programs.read', 'establishments.read', 'clients.staff.read']);
        $this->ownerRole   = $this->makeRole('client-owner');
        $this->managerRole = $this->makeRole('client-manager');

        $this->country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54',
        ]);
        $this->documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $this->country->id, 'name' => 'CUIT', 'validation_regex' => '.*',
        ]);

        $this->vet           = $this->makeVet();
        $this->client        = $this->makeClient($this->vet);
        $this->establishment = $this->makeEstablishment($this->client);
    }

    private function makeRole(string $name): Role
    {
        return Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
    }

    private function makeVet(): Vet
    {
        return Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet ' . Str::random(4),
            'slug' => 'vet-' . Str::random(8), 'country_id' => $this->country->id,
            'document_type_id' => $this->documentType->id, 'tax_id' => '20-' . random_int(10000000, 99999999) . '-9',
            'validated_at' => now(),
        ]);
    }

    private function makeClient(?Vet $vet = null): Client
    {
        $client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente ' . Str::random(4),
            'country_id' => $this->country->id, 'document_type_id' => $this->documentType->id,
            'tax_id' => '20-' . random_int(10000000, 99999999) . '-1',
        ]);
        if ($vet) {
            $client->vets()->attach($vet);
        }

        return $client;
    }

    private function makeEstablishment(Client $client): Establishment
    {
        return Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $client->id, 'name' => 'Estancia']);
    }

    private function makeProfile(User $user, string $type, int $ownerId, Role $role): UserProfile
    {
        return UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => $user->id,
            'authenticatable_type' => $type, 'authenticatable_id' => $ownerId, 'role_id' => $role->id,
        ]);
    }

    private function clientProfile(string $name, ?Role $role = null): UserProfile
    {
        return $this->makeProfile(User::factory()->create(['name' => $name]), 'client', $this->client->id, $role ?? $this->ownerRole);
    }

    private function linked(string $name, ?Role $role = null, bool $blocked = false): UserProfile
    {
        $profile = $this->clientProfile($name, $role);
        if ($blocked) {
            $profile->forceFill(['blocked_at' => now()])->save();
        }
        $this->establishment->staff()->attach($profile->id);

        return $profile;
    }

    private function actorWithRole(string $roleName, ?Vet $vet = null): User
    {
        $user = User::factory()->create();
        $this->makeProfile($user, 'vet', ($vet ?? $this->vet)->id, Role::where('name', $roleName)->firstOrFail());

        return $user;
    }

    private function url(?Establishment $est = null, ?Client $client = null): string
    {
        return '/api/v1/vets/' . $this->vet->guid . '/clients/' . ($client ?? $this->client)->guid
            . '/establishments/' . ($est ?? $this->establishment)->guid . '/manager-options';
    }

    public function test_returns_only_guid_name_and_role_of_linked_staff(): void
    {
        $owner = $this->linked('Ana Owner');
        $this->linked('Beto Manager', $this->managerRole);

        $response = $this->actingAs($this->actorWithRole('vet'), 'sanctum')
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.guid', $owner->guid)
            ->assertJsonPath('data.0.name', 'Ana Owner')
            ->assertJsonPath('data.0.role', 'client-owner')
            ->assertJsonPath('data.1.role', 'client-manager');

        foreach ($response->json('data') as $item) {
            $this->assertSame(['guid', 'name', 'role'], array_keys($item));
        }
        $this->assertStringNotContainsString('@', $response->getContent());
        $this->assertStringNotContainsString('contacts', $response->getContent());
    }

    public function test_vet_assistant_without_staff_read_is_authorized(): void
    {
        $this->linked('Ana Owner');

        $this->actingAs($this->actorWithRole('vet-assistant'), 'sanctum')
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_role_without_managers_permission_gets_403(): void
    {
        $this->linked('Ana Owner');

        foreach (['vet-viewer', 'vet-no-managers'] as $role) {
            $this->actingAs($this->actorWithRole($role), 'sanctum')
                ->getJson($this->url())
                ->assertForbidden();
        }
    }

    public function test_blocked_profiles_are_excluded(): void
    {
        $active = $this->linked('Activo');
        $this->linked('Bloqueado', null, true);

        $this->actingAs($this->actorWithRole('vet'), 'sanctum')
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guid', $active->guid);
    }

    public function test_unlinked_client_staff_is_excluded(): void
    {
        $this->linked('Vinculado');
        $this->clientProfile('Sin vinculo');

        $other   = $this->makeEstablishment($this->client);
        $inOther = $this->clientProfile('Otro establecimiento', $this->managerRole);
        $other->staff()->attach($inOther->id);

        $this->actingAs($this->actorWithRole('vet'), 'sanctum')
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Vinculado');
    }

    public function test_establishment_of_another_client_returns_404(): void
    {
        $foreignEstablishment = $this->makeEstablishment($this->makeClient($this->vet));

        $this->actingAs($this->actorWithRole('vet'), 'sanctum')
            ->getJson($this->url($foreignEstablishment))
            ->assertNotFound();
    }

    public function test_client_of_another_vet_returns_404(): void
    {
        $otherClient = $this->makeClient($this->makeVet());
        $otherEst    = $this->makeEstablishment($otherClient);

        $this->actingAs($this->actorWithRole('vet'), 'sanctum')
            ->getJson($this->url($otherEst, $otherClient))
            ->assertNotFound();
    }

    public function test_actor_of_another_vet_cannot_use_this_tenant(): void
    {
        $this->linked('Ana Owner');

        $this->actingAs($this->actorWithRole('vet', $this->makeVet()), 'sanctum')
            ->getJson($this->url())
            ->assertForbidden();
    }
}
