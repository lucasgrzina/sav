<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TechniqueControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $vetUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear permisos mínimos necesarios
        Permission::firstOrCreate(
            ['name' => 'techniques.read', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString()],
        );

        // Crear rol vet
        $role = Role::firstOrCreate(
            ['name' => 'vet', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => 'tenant'],
        );

        // Crear usuario autenticado (cualquier usuario autenticado puede leer técnicas)
        $this->vetUser = User::factory()->create();
        $this->vetUser->assignRole('vet');
    }

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    public function test_index_returns_full_hierarchy(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Inseminación Artificial',
            'type' => 'technique',
        ]);

        Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'IA a tiempo fijo',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Vacuna Aftosa',
            'type' => 'vaccine',
        ]);

        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->getJson('/api/v1/techniques');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');  // 2 raíces

        // Verificar que la raíz incluye sus hijos
        $rootData = collect($response->json('data'))->firstWhere('name', 'Inseminación Artificial');
        $this->assertNotNull($rootData);
        $this->assertCount(1, $rootData['children']);
        $this->assertEquals('IA a tiempo fijo', $rootData['children'][0]['name']);
    }

    public function test_index_filters_by_type(): void
    {
        Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Inseminación Artificial',
            'type' => 'technique',
        ]);

        Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Vacuna Aftosa',
            'type' => 'vaccine',
        ]);

        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->getJson('/api/v1/techniques?type=technique');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'technique');
    }

    public function test_index_does_not_return_children_as_top_level(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Raíz',
            'type' => 'technique',
        ]);

        Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'Hijo',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->getJson('/api/v1/techniques');

        // Solo debe haber 1 elemento (la raíz), no 2
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/techniques')->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // protocols (stub)
    // -------------------------------------------------------------------------

    public function test_protocols_returns_empty_array(): void
    {
        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->getJson('/api/v1/techniques/protocols');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }

    // -------------------------------------------------------------------------
    // techniqueProtocols (stub)
    // -------------------------------------------------------------------------

    public function test_technique_protocols_returns_empty_array(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Inseminación Artificial',
            'type' => 'technique',
        ]);

        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->getJson("/api/v1/techniques/{$root->guid}/protocols");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }

    public function test_technique_protocols_returns_404_for_nonexistent_guid(): void
    {
        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->getJson('/api/v1/techniques/' . Str::uuid()->toString() . '/protocols');

        $response->assertStatus(404);
    }

    public function test_technique_protocols_returns_404_for_child_guid(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Raíz',
            'type' => 'technique',
        ]);

        $child = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'Hijo',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->getJson("/api/v1/techniques/{$child->guid}/protocols");

        $response->assertStatus(404);
    }
}
