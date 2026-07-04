<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTechniqueControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear permisos de técnicas
        $permissions = [
            'techniques.read',
            'techniques.create',
            'techniques.update',
            'techniques.delete',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        // Crear rol super-admin con todos los permisos
        $role = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => 'platform'],
        );
        $role->syncPermissions(Permission::all());

        // Crear usuario super-admin autenticado
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super-admin');
    }

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    public function test_index_returns_paginated_roots(): void
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

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/techniques');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data',
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 2);
    }

    public function test_index_filters_by_search(): void
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

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/techniques?search=Vacuna');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.name', 'Vacuna Aftosa');
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

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/techniques?type=vaccine');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.type', 'vaccine');
    }

    public function test_index_returns_only_roots_not_children(): void
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

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/techniques');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.name', 'Raíz');
    }

    public function test_index_returns_children_count(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Raíz',
            'type' => 'technique',
        ]);

        Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'Hijo 1',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'Hijo 2',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/techniques');

        $response->assertStatus(200)
            ->assertJsonPath('data.data.0.children_count', 2);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/techniques')->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // store
    // -------------------------------------------------------------------------

    public function test_store_creates_root_without_children(): void
    {
        $payload = [
            'name' => 'Inseminación Artificial',
            'type' => 'technique',
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/techniques', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Inseminación Artificial')
            ->assertJsonPath('data.type', 'technique')
            ->assertJsonPath('data.is_root', true)
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.children_count', 0)
            ->assertJsonStructure(['data' => ['guid']]);

        $this->assertDatabaseHas('techniques', [
            'name' => 'Inseminación Artificial',
            'type' => 'technique',
        ]);
    }

    public function test_store_creates_root_with_children(): void
    {
        $payload = [
            'name'     => 'Inseminación Artificial',
            'type'     => 'technique',
            'children' => [
                ['name' => 'IA a tiempo fijo', 'protocols_name' => 'Protocolo IATF'],
                ['name' => 'IA convencional',  'protocols_name' => null],
            ],
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/techniques', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.children_count', 2)
            ->assertJsonCount(2, 'data.children');

        $this->assertDatabaseHas('techniques', ['name' => 'IA a tiempo fijo']);
        $this->assertDatabaseHas('techniques', ['name' => 'IA convencional']);
    }

    public function test_store_fails_validation_when_name_is_empty(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/techniques', ['type' => 'technique']);

        $response->assertStatus(422);
    }

    public function test_store_fails_validation_when_type_is_invalid(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/techniques', [
                'name' => 'Test',
                'type' => 'invalid_type',
            ]);

        $response->assertStatus(422);
    }

    public function test_store_children_inherit_parent_type(): void
    {
        $payload = [
            'name'     => 'Vacuna Aftosa',
            'type'     => 'vaccine',
            'children' => [
                ['name' => 'Dosis única'],
            ],
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/techniques', $payload);

        $response->assertStatus(201);

        $child = Technique::where('name', 'Dosis única')->first();
        $this->assertNotNull($child);
        $this->assertEquals('vaccine', $child->type);
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    public function test_show_returns_detail_with_children(): void
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

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/techniques/{$root->guid}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.technique.guid', $root->guid)
            ->assertJsonPath('data.technique.name', 'Inseminación Artificial')
            ->assertJsonCount(1, 'data.technique.children')
            ->assertJsonPath('data.programs.total', 0);
    }

    public function test_show_returns_404_for_nonexistent_guid(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/techniques/' . Str::uuid()->toString());

        $response->assertStatus(404);
    }

    public function test_show_returns_404_for_child_guid(): void
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

        // show solo acepta raíces — debe retornar 404 para un hijo
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/techniques/{$child->guid}");

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    public function test_update_changes_root_name(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Nombre original',
            'type' => 'technique',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/techniques/{$root->guid}", [
                'name'     => 'Nombre actualizado',
                'type'     => 'technique',
                'children' => [],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Nombre actualizado');

        $this->assertDatabaseHas('techniques', [
            'guid' => $root->guid,
            'name' => 'Nombre actualizado',
        ]);
    }

    public function test_update_adds_new_child(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Raíz',
            'type' => 'technique',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/techniques/{$root->guid}", [
                'name'     => 'Raíz',
                'type'     => 'technique',
                'children' => [
                    ['name' => 'Hijo nuevo', 'protocols_name' => null],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.children');

        $this->assertDatabaseHas('techniques', ['name' => 'Hijo nuevo']);
    }

    public function test_update_removes_child_not_in_list(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Raíz',
            'type' => 'technique',
        ]);

        $child = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'Hijo a eliminar',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        // Update sin incluir el hijo → debe eliminarse
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/techniques/{$root->guid}", [
                'name'     => 'Raíz',
                'type'     => 'technique',
                'children' => [],
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.children');

        $this->assertDatabaseMissing('techniques', ['guid' => $child->guid]);
    }

    public function test_update_updates_existing_child(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Raíz',
            'type' => 'technique',
        ]);

        $child = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'Nombre original',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/techniques/{$root->guid}", [
                'name'     => 'Raíz',
                'type'     => 'technique',
                'children' => [
                    ['guid' => $child->guid, 'name' => 'Nombre actualizado', 'protocols_name' => 'Protocolo X'],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('techniques', [
            'guid' => $child->guid,
            'name' => 'Nombre actualizado',
        ]);
    }

    public function test_update_returns_404_for_nonexistent_guid(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/v1/admin/techniques/' . Str::uuid()->toString(), [
                'name'     => 'Test',
                'type'     => 'technique',
                'children' => [],
            ]);

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // destroy
    // -------------------------------------------------------------------------

    public function test_destroy_deletes_root_without_children(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Técnica a eliminar',
            'type' => 'technique',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/techniques/{$root->guid}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('techniques', ['guid' => $root->guid]);
    }

    public function test_destroy_deletes_root_and_its_children(): void
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

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/techniques/{$root->guid}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('techniques', ['guid' => $root->guid]);
        $this->assertDatabaseMissing('techniques', ['guid' => $child->guid]);
    }

    public function test_destroy_returns_404_for_nonexistent_guid(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson('/api/v1/admin/techniques/' . Str::uuid()->toString());

        $response->assertStatus(404);
    }

    public function test_destroy_does_not_expose_internal_id(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Test',
            'type' => 'technique',
        ]);

        // El show no debe exponer 'id' en la respuesta
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/techniques/{$root->guid}");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('id', $response->json('data.technique'));
    }
}
