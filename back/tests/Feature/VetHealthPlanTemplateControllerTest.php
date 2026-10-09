<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\EstablishmentHealthPlan;
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

class VetHealthPlanTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private Vet $otherVet;
    private HealthPlanCategory $category;
    private HealthPlanTemplate $globalTemplate;
    private int $isoCodeCounter = 0;

    /**
     * countries.iso_code es char(2) unique — se genera determinísticamente para no chocar
     * entre los dos vets creados en este test (mismo patrón que EstablishmentHealthPlanControllerTest).
     */
    private function nextIsoCode(): string
    {
        $n = $this->isoCodeCounter++;

        return chr(65 + intdiv($n, 26)) . chr(65 + ($n % 26));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $readPermission = 'establishment-health-plans.read';
        $templatePermissions = [
            'establishment-health-plans.templates.create',
            'establishment-health-plans.templates.update',
            'establishment-health-plans.templates.delete',
        ];

        foreach ([$readPermission, ...$templatePermissions] as $name) {
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

        // vet y vet-assistant: lectura + escritura de plantillas propias.
        foreach (['vet', 'vet-assistant'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $role->givePermissionTo($readPermission);
            $role->givePermissionTo(Permission::whereIn('name', $templatePermissions)->get());
        }

        // vet-administrative: solo lectura (DEC-NEG-02).
        Role::where('name', 'vet-administrative')->first()->givePermissionTo($readPermission);

        $this->vet      = $this->createVet('Vet Test');
        $this->otherVet = $this->createVet('Otro Vet');

        $this->category = HealthPlanCategory::create(['guid' => Str::uuid()->toString(), 'name' => 'Categoria Test']);

        $this->globalTemplate = HealthPlanTemplate::create([
            'guid'                    => Str::uuid()->toString(),
            'name'                    => 'Plan Global',
            'health_plan_category_id' => $this->category->id,
            'vet_id'                  => null,
        ]);
    }

    private function createVet(string $name): Vet
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina ' . $name,
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

    private function createOwnTemplate(Vet $vet, string $name = 'Plan Propio'): HealthPlanTemplate
    {
        return HealthPlanTemplate::create([
            'guid'                    => Str::uuid()->toString(),
            'name'                    => $name,
            'health_plan_category_id' => $this->category->id,
            'vet_id'                  => $vet->id,
        ]);
    }

    /**
     * Instancia un EstablishmentHealthPlan real contra la plantilla dada, para simular
     * el bloqueo DEC-NEG-04 ("la plantilla ya generó un plan").
     */
    private function instantiatePlanFor(HealthPlanTemplate $template, Vet $vet): EstablishmentHealthPlan
    {
        $client = Client::create([
            'guid'             => Str::uuid()->toString(),
            'name'             => 'Cliente Test',
            'country_id'       => $vet->country_id,
            'document_type_id' => $vet->document_type_id,
            'tax_id'           => '20-99999999-9',
        ]);
        $vet->clients()->attach($client->id, ['created_at' => now(), 'updated_at' => now()]);

        $establishment = Establishment::create([
            'guid'      => Str::uuid()->toString(),
            'client_id' => $client->id,
            'name'      => 'Establecimiento Test',
        ]);

        return EstablishmentHealthPlan::create([
            'guid'                    => Str::uuid()->toString(),
            'vet_id'                  => $vet->id,
            'client_id'               => $client->id,
            'establishment_id'        => $establishment->id,
            'health_plan_template_id' => $template->id,
            'year'                    => 2026,
            'starts_on'               => '2026-07-01',
            'ends_on'                 => '2027-06-30',
        ]);
    }

    public function test_index_returns_own_and_global_templates_but_not_other_vet_templates(): void
    {
        $ownTemplate = $this->createOwnTemplate($this->vet);
        $otherVetTemplate = $this->createOwnTemplate($this->otherVet, 'Plan de otro vet');

        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates?per_page=50");

        $response->assertStatus(200);

        $guids = collect($response->json('data.data'))->pluck('guid');
        $this->assertTrue($guids->contains($ownTemplate->guid));
        $this->assertTrue($guids->contains($this->globalTemplate->guid));
        $this->assertFalse($guids->contains($otherVetTemplate->guid));
    }

    public function test_index_filters_by_scope_own(): void
    {
        $ownTemplate = $this->createOwnTemplate($this->vet);
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates?scope=own");

        $response->assertStatus(200);
        $guids = collect($response->json('data.data'))->pluck('guid');
        $this->assertEquals([$ownTemplate->guid], $guids->all());
    }

    public function test_index_filters_by_scope_global(): void
    {
        $this->createOwnTemplate($this->vet);
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates?scope=global");

        $response->assertStatus(200);
        $guids = collect($response->json('data.data'))->pluck('guid');
        $this->assertEquals([$this->globalTemplate->guid], $guids->all());
    }

    public function test_show_of_other_vet_template_returns_404(): void
    {
        $otherVetTemplate = $this->createOwnTemplate($this->otherVet);
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates/{$otherVetTemplate->guid}");

        $response->assertStatus(404);
    }

    public function test_store_with_vet_role_creates_own_template(): void
    {
        $user = $this->createUserForVet($this->vet);
        $activity = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates", [
                'name'                       => 'Mi plantilla',
                'health_plan_category_guid'  => $this->category->guid,
                'activities'                 => [
                    ['health_activity_guid' => $activity->guid, 'months' => [1, 6]],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('health_plan_templates', [
            'guid'   => $response->json('data.guid'),
            'vet_id' => $this->vet->id,
        ]);
    }

    public function test_store_without_permission_returns_403(): void
    {
        $user = $this->createUserForVet($this->vet, 'vet-administrative');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates", [
                'name'                      => 'Plantilla no permitida',
                'health_plan_category_guid' => $this->category->guid,
            ]);

        $response->assertStatus(403);
    }

    public function test_update_of_own_template_without_instances_succeeds(): void
    {
        $template = $this->createOwnTemplate($this->vet);
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates/{$template->guid}", [
                'name'                      => 'Nombre actualizado',
                'health_plan_category_guid' => $this->category->guid,
            ]);

        $response->assertStatus(200)->assertJsonPath('data.name', 'Nombre actualizado');
    }

    public function test_destroy_of_own_template_without_instances_succeeds(): void
    {
        $template = $this->createOwnTemplate($this->vet);
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates/{$template->guid}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('health_plan_templates', ['guid' => $template->guid]);
    }

    public function test_update_of_own_template_with_instantiated_plan_is_locked(): void
    {
        $template = $this->createOwnTemplate($this->vet);
        $this->instantiatePlanFor($template, $this->vet);
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates/{$template->guid}", [
                'name'                      => 'No debería aplicarse',
                'health_plan_category_guid' => $this->category->guid,
            ]);

        $response->assertStatus(422)->assertJsonPath('errors.reason', 'template_locked');
    }

    public function test_destroy_of_own_template_with_instantiated_plan_is_locked(): void
    {
        $template = $this->createOwnTemplate($this->vet);
        $this->instantiatePlanFor($template, $this->vet);
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates/{$template->guid}");

        $response->assertStatus(422)->assertJsonPath('errors.reason', 'template_locked');
        $this->assertDatabaseHas('health_plan_templates', ['guid' => $template->guid]);
    }

    public function test_update_of_other_vet_template_returns_404(): void
    {
        $otherVetTemplate = $this->createOwnTemplate($this->otherVet);
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates/{$otherVetTemplate->guid}", [
                'name'                      => 'Intento ajeno',
                'health_plan_category_guid' => $this->category->guid,
            ]);

        $response->assertStatus(404);
    }

    public function test_destroy_of_other_vet_template_returns_404(): void
    {
        $otherVetTemplate = $this->createOwnTemplate($this->otherVet);
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates/{$otherVetTemplate->guid}");

        $response->assertStatus(404);
    }

    public function test_update_of_global_template_from_tenant_panel_returns_404(): void
    {
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates/{$this->globalTemplate->guid}", [
                'name'                      => 'Intento sobre global',
                'health_plan_category_guid' => $this->category->guid,
            ]);

        $response->assertStatus(404);
    }

    public function test_destroy_of_global_template_from_tenant_panel_returns_404(): void
    {
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/vets/{$this->vet->guid}/health-plan-templates/{$this->globalTemplate->guid}");

        $response->assertStatus(404);
    }
}
