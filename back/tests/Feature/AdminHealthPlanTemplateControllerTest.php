<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\DocumentType;
use App\Models\HealthPlanCategory;
use App\Models\HealthPlanTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regresión de seguridad TKT-008: el panel admin (super-admin) debe operar
 * EXCLUSIVAMENTE sobre plantillas globales (vet_id IS NULL). Antes de este fix,
 * `HealthPlanTemplateRepositoryEloquent::findByGuid()` no filtraba por vet_id,
 * permitiendo que show/update/destroy alcanzaran plantillas privadas de un vet
 * (bypasseando además el bloqueo DEC-NEG-04 en el path de update/destroy).
 */
class AdminHealthPlanTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private HealthPlanCategory $category;
    private Vet $vet;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'health-plan-templates.read',
            'health-plan-templates.create',
            'health-plan-templates.update',
            'health-plan-templates.delete',
        ];

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

        $this->category = HealthPlanCategory::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Categoria Test',
        ]);

        $this->vet = $this->createVet('Vet Test');
    }

    private function createVet(string $name): Vet
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina ' . $name,
            'iso_code'     => 'AR',
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

    private function createGlobalTemplate(string $name = 'Plan Global'): HealthPlanTemplate
    {
        return HealthPlanTemplate::create([
            'guid'                    => Str::uuid()->toString(),
            'name'                    => $name,
            'health_plan_category_id' => $this->category->id,
            'vet_id'                  => null,
        ]);
    }

    private function createVetOwnedTemplate(string $name = 'Plan Propio del Vet'): HealthPlanTemplate
    {
        return HealthPlanTemplate::create([
            'guid'                    => Str::uuid()->toString(),
            'name'                    => $name,
            'health_plan_category_id' => $this->category->id,
            'vet_id'                  => $this->vet->id,
        ]);
    }

    public function test_index_never_includes_vet_owned_templates(): void
    {
        $global = $this->createGlobalTemplate();
        $vetOwned = $this->createVetOwnedTemplate();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/health-plan-templates?per_page=50');

        $response->assertStatus(200);
        $guids = collect($response->json('data.data'))->pluck('guid');
        $this->assertTrue($guids->contains($global->guid));
        $this->assertFalse($guids->contains($vetOwned->guid));
    }

    public function test_show_of_vet_owned_template_returns_404(): void
    {
        $vetOwned = $this->createVetOwnedTemplate();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/health-plan-templates/{$vetOwned->guid}");

        $response->assertStatus(404);
    }

    public function test_update_of_vet_owned_template_returns_404(): void
    {
        $vetOwned = $this->createVetOwnedTemplate();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/health-plan-templates/{$vetOwned->guid}", [
                'name'                      => 'Intento de admin sobre plantilla de vet',
                'health_plan_category_guid' => $this->category->guid,
            ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('health_plan_templates', [
            'guid' => $vetOwned->guid,
            'name' => 'Plan Propio del Vet',
        ]);
    }

    public function test_destroy_of_vet_owned_template_returns_404(): void
    {
        $vetOwned = $this->createVetOwnedTemplate();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/health-plan-templates/{$vetOwned->guid}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('health_plan_templates', ['guid' => $vetOwned->guid]);
    }

    public function test_show_of_global_template_still_works(): void
    {
        $global = $this->createGlobalTemplate();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/health-plan-templates/{$global->guid}");

        $response->assertStatus(200)->assertJsonPath('data.guid', $global->guid);
    }

    public function test_update_of_global_template_still_works(): void
    {
        $global = $this->createGlobalTemplate();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/health-plan-templates/{$global->guid}", [
                'name'                      => 'Nombre actualizado',
                'health_plan_category_guid' => $this->category->guid,
            ]);

        $response->assertStatus(200)->assertJsonPath('data.name', 'Nombre actualizado');
    }

    public function test_destroy_of_global_template_without_instances_still_works(): void
    {
        $global = $this->createGlobalTemplate();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/health-plan-templates/{$global->guid}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('health_plan_templates', ['guid' => $global->guid]);
    }
}
