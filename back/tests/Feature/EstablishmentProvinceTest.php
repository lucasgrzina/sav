<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Permission;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstablishmentProvinceTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;
    private Vet $vet;
    private Client $client;
    private User $vetUser;
    private User $admin;
    private Province $cordoba;
    private Province $santaFe;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['establishments.create', 'establishments.update', 'establishments.read', 'clients.staff.read'] as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $vetRole = Role::firstOrCreate(
            ['name' => 'vet', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
        $vetRole->givePermissionTo(['establishments.create', 'establishments.update', 'establishments.read']);
        Role::firstOrCreate(
            ['name' => 'platform-admin', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
        )->givePermissionTo(['establishments.create', 'establishments.update', 'establishments.read']);

        $this->country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $this->country->id, 'name' => 'CUIT', 'validation_regex' => '.*',
        ]);
        $this->vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet', 'slug' => 'vet-' . Str::random(8),
            'country_id' => $this->country->id, 'document_type_id' => $documentType->id,
            'tax_id' => '20-' . random_int(10000000, 99999999) . '-9', 'validated_at' => now(),
        ]);
        $this->client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente',
            'country_id' => $this->country->id, 'document_type_id' => $documentType->id,
            'tax_id' => '20-' . random_int(10000000, 99999999) . '-1',
        ]);
        $this->client->vets()->attach($this->vet);

        $this->vetUser = User::factory()->create();
        UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => $this->vetUser->id,
            'authenticatable_type' => 'vet', 'authenticatable_id' => $this->vet->id, 'role_id' => $vetRole->id,
        ]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('platform-admin');

        $this->cordoba = Province::create(['guid' => Str::uuid()->toString(), 'country_id' => $this->country->id, 'name' => 'Córdoba']);
        $this->santaFe = Province::create(['guid' => Str::uuid()->toString(), 'country_id' => $this->country->id, 'name' => 'Santa Fe']);
    }

    private function tenantUrl(string $suffix = ''): string
    {
        return "/api/v1/vets/{$this->vet->guid}/clients/{$this->client->guid}/establishments{$suffix}";
    }

    private function adminUrl(string $suffix = ''): string
    {
        return "/api/v1/admin/clients/{$this->client->guid}/establishments{$suffix}";
    }

    private function makeEstablishment(array $attrs = []): Establishment
    {
        return Establishment::create(array_merge([
            'guid' => Str::uuid()->toString(), 'client_id' => $this->client->id, 'name' => 'Estancia',
        ], $attrs));
    }

    public function test_lists_provinces_of_a_country_ordered_by_name(): void
    {
        $uruguay = Country::create(['guid' => Str::uuid()->toString(), 'name' => 'Uruguay', 'iso_code' => 'UY', 'phone_prefix' => '+598']);
        Province::create(['guid' => Str::uuid()->toString(), 'country_id' => $uruguay->id, 'name' => 'Salto']);

        $this->actingAs($this->vetUser, 'sanctum')
            ->getJson("/api/v1/countries/{$this->country->guid}/provinces")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Córdoba')
            ->assertJsonPath('data.0.guid', $this->cordoba->guid)
            ->assertJsonPath('data.1.name', 'Santa Fe')
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_provinces_unknown_country_returns_404(): void
    {
        $this->actingAs($this->vetUser, 'sanctum')
            ->getJson('/api/v1/countries/' . Str::uuid() . '/provinces')
            ->assertNotFound();
    }

    public function test_provinces_require_authentication(): void
    {
        $this->getJson("/api/v1/countries/{$this->country->guid}/provinces")->assertUnauthorized();
    }

    public function test_tenant_store_with_province_sets_province_and_syncs_state(): void
    {
        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['name' => 'Est', 'state' => 'texto ignorado', 'province_guid' => $this->cordoba->guid])
            ->assertCreated()
            ->assertJsonPath('data.state', 'Córdoba')
            ->assertJsonPath('data.province.guid', $this->cordoba->guid)
            ->assertJsonPath('data.province.name', 'Córdoba');

        $this->assertDatabaseHas('establishments', ['name' => 'Est', 'state' => 'Córdoba', 'province_id' => $this->cordoba->id]);
    }

    public function test_store_with_unknown_province_is_rejected(): void
    {
        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['name' => 'Est', 'province_guid' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('province_guid');
    }

    public function test_store_without_province_keeps_legacy_state_text(): void
    {
        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['name' => 'Est', 'state' => 'Entre Ríos'])
            ->assertCreated();

        $this->assertDatabaseHas('establishments', ['name' => 'Est', 'state' => 'Entre Ríos', 'province_id' => null]);
    }

    public function test_tenant_update_changes_province_and_syncs_state(): void
    {
        $est = $this->makeEstablishment(['state' => 'Córdoba', 'province_id' => $this->cordoba->id]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->putJson($this->tenantUrl("/{$est->guid}"), ['province_guid' => $this->santaFe->guid])
            ->assertOk()
            ->assertJsonPath('data.state', 'Santa Fe')
            ->assertJsonPath('data.province.guid', $this->santaFe->guid);

        $this->assertDatabaseHas('establishments', ['id' => $est->id, 'state' => 'Santa Fe', 'province_id' => $this->santaFe->id]);
    }

    public function test_update_with_null_province_clears_province_id(): void
    {
        $est = $this->makeEstablishment(['state' => 'Córdoba', 'province_id' => $this->cordoba->id]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->putJson($this->tenantUrl("/{$est->guid}"), ['province_guid' => null, 'state' => null])
            ->assertOk()
            ->assertJsonPath('data.province', null);

        $this->assertDatabaseHas('establishments', ['id' => $est->id, 'state' => null, 'province_id' => null]);
    }

    public function test_update_with_only_different_state_text_clears_stale_province_id(): void
    {
        $est = $this->makeEstablishment(['state' => 'Córdoba', 'province_id' => $this->cordoba->id]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->putJson($this->tenantUrl("/{$est->guid}"), ['state' => 'Otra'])
            ->assertOk();

        $this->assertDatabaseHas('establishments', ['id' => $est->id, 'state' => 'Otra', 'province_id' => null]);
    }

    public function test_update_without_state_or_province_leaves_both_untouched(): void
    {
        $est = $this->makeEstablishment(['state' => 'Córdoba', 'province_id' => $this->cordoba->id]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->putJson($this->tenantUrl("/{$est->guid}"), ['name' => 'Renombrada'])
            ->assertOk();

        $this->assertDatabaseHas('establishments', ['id' => $est->id, 'name' => 'Renombrada', 'state' => 'Córdoba', 'province_id' => $this->cordoba->id]);
    }

    public function test_admin_store_and_update_with_province(): void
    {
        $guid = $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->adminUrl(), ['name' => 'Admin Est', 'province_guid' => $this->santaFe->guid])
            ->assertCreated()
            ->assertJsonPath('data.state', 'Santa Fe')
            ->json('data.guid');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->adminUrl("/{$guid}"), ['province_guid' => $this->cordoba->guid])
            ->assertOk()
            ->assertJsonPath('data.province.name', 'Córdoba');

        $this->assertDatabaseHas('establishments', ['guid' => $guid, 'state' => 'Córdoba', 'province_id' => $this->cordoba->id]);
    }

    public function test_index_exposes_province(): void
    {
        $this->makeEstablishment(['state' => 'Córdoba', 'province_id' => $this->cordoba->id]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->getJson($this->tenantUrl())
            ->assertOk()
            ->assertJsonPath('data.0.province.guid', $this->cordoba->guid);
    }

    public function test_province_seeder_is_idempotent_and_loads_24_jurisdictions(): void
    {
        $this->seed(ProvinceSeeder::class);
        $this->seed(ProvinceSeeder::class);

        // Córdoba and Santa Fe already exist from setUp and must not be duplicated.
        $this->assertSame(24, Province::where('country_id', $this->country->id)->count());
        $this->assertSame(1, Province::where('name', 'Córdoba')->count());
    }
}
