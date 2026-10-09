<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstablishmentGeocodeControllerTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private User $vetUser;
    private User $assistantUser;
    private User $admin;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['establishments.create', 'establishments.read'] as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $vetRole = $this->makeRole('vet', Role::TYPE_TENANT);
        $vetRole->givePermissionTo(['establishments.create', 'establishments.read']);
        $assistantRole = $this->makeRole('vet-assistant', Role::TYPE_TENANT);
        $assistantRole->givePermissionTo(['establishments.read']);
        $this->makeRole('platform-admin', Role::TYPE_PLATFORM)->givePermissionTo(['establishments.create', 'establishments.read']);
        $this->makeRole('platform-viewer', Role::TYPE_PLATFORM)->givePermissionTo(['establishments.read']);

        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*',
        ]);
        $this->vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet', 'slug' => 'vet-' . Str::random(8),
            'country_id' => $country->id, 'document_type_id' => $documentType->id,
            'tax_id' => '20-' . random_int(10000000, 99999999) . '-9', 'validated_at' => now(),
        ]);

        $this->vetUser       = $this->makeVetUser($vetRole);
        $this->assistantUser = $this->makeVetUser($assistantRole);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('platform-admin');
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('platform-viewer');

        Cache::flush();
    }

    private function makeRole(string $name, string $type): Role
    {
        return Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => $type],
        );
    }

    private function makeVetUser(Role $role): User
    {
        $user = User::factory()->create();
        UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => $user->id,
            'authenticatable_type' => 'vet', 'authenticatable_id' => $this->vet->id, 'role_id' => $role->id,
        ]);

        return $user;
    }

    private function tenantUrl(): string
    {
        return "/api/v1/vets/{$this->vet->guid}/establishments/geocode";
    }

    private function adminUrl(): string
    {
        return '/api/v1/admin/establishments/geocode';
    }

    private function fakeFound(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '-34.6037389', 'lon' => '-58.3815704', 'display_name' => 'Buenos Aires'],
            ]),
        ]);
    }

    public function test_tenant_user_gets_coordinates_and_request_is_well_formed(): void
    {
        $this->fakeFound();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => 'Av. Corrientes 1234', 'city' => 'CABA'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.latitude', -34.6037389)
            ->assertJsonPath('data.longitude', -58.3815704);

        Http::assertSent(function (HttpRequest $request) {
            return str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search')
                && $request['format'] === 'jsonv2'
                && (int) $request['limit'] === 1
                && $request['accept-language'] === 'es'
                && $request['q'] === 'av. corrientes 1234, caba'
                && $request->hasHeader('User-Agent', config('services.nominatim.user_agent'));
        });
    }

    public function test_admin_can_geocode(): void
    {
        $this->fakeFound();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->adminUrl(), ['city' => 'Rosario'])
            ->assertOk()
            ->assertJsonPath('data.latitude', -34.6037389);
    }

    public function test_not_found_returns_null_coordinates(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => 'zzzz inexistente'])
            ->assertOk()
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null);
    }

    public function test_upstream_error_degrades_gracefully_and_is_not_cached(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::sequence()
                ->push('boom', 503)
                ->push([['lat' => '-34.6037389', 'lon' => '-58.3815704']]),
        ]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => 'Calle Falsa 123'])
            ->assertOk()
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null);

        // A failure must not poison the cache: once upstream recovers the result is fetched.
        $this->travel(2)->seconds();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => 'Calle Falsa 123'])
            ->assertOk()
            ->assertJsonPath('data.latitude', -34.6037389);
    }

    public function test_timeout_degrades_gracefully(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timed out');
        });

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => 'Calle Falsa 123'])
            ->assertOk()
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null);
    }

    public function test_successful_results_are_cached_by_normalized_query(): void
    {
        $this->fakeFound();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => 'Av. Corrientes 1234', 'city' => 'CABA'])
            ->assertOk();

        $this->travel(2)->seconds();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => '  AV.  corrientes 1234 ', 'city' => 'caba'])
            ->assertOk()
            ->assertJsonPath('data.latitude', -34.6037389);

        Http::assertSentCount(1);
    }

    public function test_requires_address_or_city(): void
    {
        Http::fake();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['state' => 'Buenos Aires', 'zip_code' => '1000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['address', 'city']);

        Http::assertNothingSent();
    }

    public function test_rejects_non_string_and_too_long_input(): void
    {
        Http::fake();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => str_repeat('a', 256), 'zip_code' => ['x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['address', 'zip_code']);
    }

    public function test_user_without_create_permission_is_forbidden(): void
    {
        Http::fake();

        $this->actingAs($this->assistantUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => 'Calle 1'])
            ->assertForbidden();

        $this->actingAs($this->viewer, 'sanctum')
            ->postJson($this->adminUrl(), ['address' => 'Calle 1'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson($this->adminUrl(), ['address' => 'Calle 1'])->assertUnauthorized();
    }

    public function test_requests_are_throttled_to_one_per_second(): void
    {
        $this->fakeFound();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => 'Calle 1'])
            ->assertOk();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->tenantUrl(), ['address' => 'Calle 2'])
            ->assertStatus(429);
    }
}
