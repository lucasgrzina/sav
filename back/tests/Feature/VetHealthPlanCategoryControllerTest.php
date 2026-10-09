<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\DocumentType;
use App\Models\HealthPlanCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gap TKT-008 — catálogo global de solo lectura de `HealthPlanCategory` para el panel tenant vet.
 * `HealthPlanCategory` no tiene ownership por vet (DEC-NEG-03): el test verifica el gate de
 * permiso/tenant, no aislamiento de datos (el catálogo es el mismo para todos).
 */
class VetHealthPlanCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private int $isoCodeCounter = 0;

    private function nextIsoCode(): string
    {
        $n = $this->isoCodeCounter++;

        return chr(65 + intdiv($n, 26)) . chr(65 + ($n % 26));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $readPermission = 'establishment-health-plans.read';

        Permission::firstOrCreate(
            ['name' => $readPermission, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString()],
        );

        foreach (['vet', 'vet-assistant', 'vet-administrative'] as $roleName) {
            Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
            );
        }

        // Los 3 roles tenant tienen `.read` — es el permiso ya usado por el resto del namespace.
        foreach (['vet', 'vet-assistant', 'vet-administrative'] as $roleName) {
            Role::where('name', $roleName)->first()->givePermissionTo($readPermission);
        }

        $this->vet = $this->createVet('Vet Test');

        HealthPlanCategory::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacas - Carne']);
        HealthPlanCategory::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacas - Leche']);
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

    public function test_vet_with_permission_lists_global_category_catalog(): void
    {
        $user = $this->createUserForVet($this->vet);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/health-plan-categories?per_page=100");

        $response->assertStatus(200);
        $names = collect($response->json('data.data'))->pluck('name');
        $this->assertTrue($names->contains('Vacas - Carne'));
        $this->assertTrue($names->contains('Vacas - Leche'));
    }

    public function test_vet_assistant_with_permission_lists_category_catalog(): void
    {
        $user = $this->createUserForVet($this->vet, 'vet-assistant');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/health-plan-categories");

        $response->assertStatus(200);
    }

    public function test_user_without_tenant_access_receives_403(): void
    {
        $otherVet = $this->createVet('Otro Vet Sin Acceso');
        $user = $this->createUserForVet($otherVet);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/health-plan-categories");

        $response->assertStatus(403);
    }

    public function test_role_without_read_permission_receives_403(): void
    {
        Role::where('name', 'vet-administrative')->first()->revokePermissionTo('establishment-health-plans.read');
        $user = $this->createUserForVet($this->vet, 'vet-administrative');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/health-plan-categories");

        $response->assertStatus(403);
    }
}
