<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\HealthActivity;
use App\Models\HealthPlanCategory;
use App\Models\HealthPlanTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstablishmentHealthPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private HealthPlanTemplate $template;
    private int $isoCodeCounter = 0;

    /**
     * countries.iso_code es char(2) unique — un Str::random(2) puede chocar por azar entre
     * dos países creados en el mismo test. Se genera determinísticamente en base 26.
     */
    private function nextIsoCode(): string
    {
        $n = $this->isoCodeCounter++;

        return chr(65 + intdiv($n, 26)) . chr(65 + ($n % 26));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'establishment-health-plans.read',
            'establishment-health-plans.create',
            'establishment-health-plans.update',
            'establishment-health-plans.confirm',
        ];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        foreach (['vet', 'vet-assistant', 'vet-administrative', 'client-owner'] as $roleName) {
            Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
            );
        }

        Role::where('name', 'vet')->first()->givePermissionTo(Permission::whereIn('name', $permissions)->get());
        Role::where('name', 'vet-administrative')->first()->givePermissionTo(Permission::whereIn('name', [
            'establishment-health-plans.read',
            'establishment-health-plans.create',
            'establishment-health-plans.update',
        ])->get());

        $this->vet = $this->createVet();
        $this->client = $this->createClient('Cliente Test');
        $this->vet->clients()->attach($this->client->id, ['created_at' => now(), 'updated_at' => now()]);

        $this->establishment = Establishment::create([
            'guid'      => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name'      => 'Establecimiento Test',
        ]);

        $category = HealthPlanCategory::create(['guid' => Str::uuid()->toString(), 'name' => 'Categoria Test']);
        $this->template = HealthPlanTemplate::create([
            'guid'                    => Str::uuid()->toString(),
            'name'                    => 'Plan Test',
            'health_plan_category_id' => $category->id,
        ]);

        $activity = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $this->template->activities()->sync([$activity->id => ['months' => [1, 6], 'sort_order' => 0]]);
    }

    private function createVet(string $name = 'Vet Test'): Vet
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina ' . $name,
            // El país de la vet no interviene en HealthPlanYear (solo el del cliente) — se
            // usa un código único por vet para no chocar con el unique de countries.iso_code.
            'iso_code'     => $this->nextIsoCode(),
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
            'name'         => 'Pais ' . $name,
            'iso_code'     => $this->nextIsoCode(),
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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'establishment_id'        => $this->establishment->guid,
            'health_plan_template_id' => $this->template->guid,
            'year'                    => 2026,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // store
    // -------------------------------------------------------------------------

    public function test_store_creates_plan_with_materialized_activities(): void
    {
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans", $this->basePayload());

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('data.activities'));
    }

    public function test_store_returns_422_on_duplicate_active_plan(): void
    {
        $user = $this->createUserForVet($this->vet);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans", $this->basePayload());

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans", $this->basePayload());

        $response->assertStatus(422)->assertJsonValidationErrors(['health_plan_template_id']);
    }

    public function test_store_rejects_establishment_not_belonging_to_vet(): void
    {
        $user = $this->createUserForVet($this->vet);
        $otherClient = $this->createClient('Otro cliente');
        $otherEstablishment = Establishment::create([
            'guid'      => Str::uuid()->toString(),
            'client_id' => $otherClient->id,
            'name'      => 'Establecimiento ajeno',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans", $this->basePayload([
                'establishment_id' => $otherEstablishment->guid,
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['establishment_id']);
    }

    // -------------------------------------------------------------------------
    // scope multi-tenant (regla dura #4)
    // -------------------------------------------------------------------------

    public function test_vet_cannot_see_plan_of_another_vet(): void
    {
        $user = $this->createUserForVet($this->vet);
        $store = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans", $this->basePayload());
        $guid = $store->json('data.guid');

        $otherVet = $this->createVet('Otra Vet');
        $otherUser = $this->createUserForVet($otherVet);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/vets/{$otherVet->guid}/establishment-health-plans/{$guid}");

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // cancel
    // -------------------------------------------------------------------------

    public function test_cancel_twice_returns_422(): void
    {
        $user = $this->createUserForVet($this->vet);
        $store = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans", $this->basePayload());
        $guid = $store->json('data.guid');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans/{$guid}/cancel");

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans/{$guid}/cancel");

        $response->assertStatus(422)->assertJson(['errors' => ['reason' => 'not_editable']]);
    }

    // -------------------------------------------------------------------------
    // confirmActivity (DU-07/DEC-06/DEC-09)
    // -------------------------------------------------------------------------

    public function test_confirm_activity_succeeds_for_vet_role(): void
    {
        $user = $this->createUserForVet($this->vet);
        $store = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans", $this->basePayload());
        $planGuid = $store->json('data.guid');
        $activityGuid = $store->json('data.activities.0.guid');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans/{$planGuid}/activities/{$activityGuid}/confirm");

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $response->json('data.status'));
        $this->assertNotNull($response->json('data.confirmed_at'));
    }

    public function test_confirm_activity_forbidden_for_vet_administrative(): void
    {
        $user = $this->createUserForVet($this->vet);
        $store = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans", $this->basePayload());
        $planGuid = $store->json('data.guid');
        $activityGuid = $store->json('data.activities.0.guid');

        $adminUser = $this->createUserForVet($this->vet, 'vet-administrative');

        $response = $this->actingAs($adminUser, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans/{$planGuid}/activities/{$activityGuid}/confirm");

        $response->assertStatus(403);
    }

    public function test_confirm_activity_is_idempotent_returning_200(): void
    {
        $user = $this->createUserForVet($this->vet);
        $store = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans", $this->basePayload());
        $planGuid = $store->json('data.guid');
        $activityGuid = $store->json('data.activities.0.guid');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans/{$planGuid}/activities/{$activityGuid}/confirm");

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/establishment-health-plans/{$planGuid}/activities/{$activityGuid}/confirm");

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $response->json('data.status'));
    }
}
