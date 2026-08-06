<?php

namespace Tests\Unit;

use App\Exceptions\TechniqueCannotBeDeletedException;
use App\Models\Protocol;
use App\Models\Technique;
use App\Repositories\TechniqueRepositoryEloquent;
use App\Services\TechniqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TechniqueServiceTest extends TestCase
{
    use RefreshDatabase;

    private TechniqueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TechniqueService(new TechniqueRepositoryEloquent());
    }

    // -------------------------------------------------------------------------
    // create
    // -------------------------------------------------------------------------

    public function test_create_creates_root_and_children_in_transaction(): void
    {
        $result = $this->service->create([
            'name'     => 'Inseminación Artificial',
            'type'     => 'technique',
            'children' => [
                ['name' => 'IA a tiempo fijo', 'protocols_name' => 'Protocolo IATF'],
                ['name' => 'IA convencional',  'protocols_name' => null],
            ],
        ]);

        $this->assertEquals('Inseminación Artificial', $result->name);
        $this->assertNull($result->parent_id);
        $this->assertCount(2, $result->children);

        $this->assertDatabaseHas('techniques', ['name' => 'Inseminación Artificial', 'parent_id' => null]);
        $this->assertDatabaseHas('techniques', ['name' => 'IA a tiempo fijo']);
        $this->assertDatabaseHas('techniques', ['name' => 'IA convencional']);
    }

    public function test_create_children_inherit_parent_type(): void
    {
        $result = $this->service->create([
            'name'     => 'Vacuna Aftosa',
            'type'     => 'vaccine',
            'children' => [
                ['name' => 'Dosis única'],
            ],
        ]);

        $child = $result->children->first();
        $this->assertEquals('vaccine', $child->type);
    }

    public function test_create_without_children(): void
    {
        $result = $this->service->create([
            'name' => 'Sin hijos',
            'type' => 'technique',
        ]);

        $this->assertEquals('Sin hijos', $result->name);
        $this->assertCount(0, $result->children);
    }

    // -------------------------------------------------------------------------
    // update prepareSync
    // -------------------------------------------------------------------------

    public function test_update_prepares_sync_correctly(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Raíz',
            'type' => 'technique',
        ]);

        $childToKeep = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'Hijo a mantener',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        $childToDelete = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'Hijo a eliminar',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);

        $result = $this->service->update($root, [
            'name'     => 'Raíz actualizada',
            'type'     => 'technique',
            'children' => [
                ['guid' => $childToKeep->guid, 'name' => 'Hijo actualizado', 'protocols_name' => null],
                ['name' => 'Hijo nuevo', 'protocols_name' => null],
            ],
        ]);

        // El hijo a eliminar debe haber desaparecido
        $this->assertDatabaseMissing('techniques', ['guid' => $childToDelete->guid]);
        // El hijo existente debe estar actualizado
        $this->assertDatabaseHas('techniques', ['guid' => $childToKeep->guid, 'name' => 'Hijo actualizado']);
        // El hijo nuevo debe existir
        $this->assertDatabaseHas('techniques', ['name' => 'Hijo nuevo']);
        // La raíz debe tener 2 hijos
        $this->assertCount(2, $result->children);
    }

    // -------------------------------------------------------------------------
    // destroy
    // -------------------------------------------------------------------------

    public function test_destroy_deletes_root_and_children(): void
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

        $this->service->destroy($root);

        $this->assertDatabaseMissing('techniques', ['guid' => $root->guid]);
        $this->assertDatabaseMissing('techniques', ['guid' => $child->guid]);
    }

    public function test_destroy_does_not_throw_when_stubs_return_zero(): void
    {
        // El stub de countProgramsForTechnique retorna 0, y countProtocolsForTechnique ahora
        // es una query real (sin protocolos creados acá) — el destroy debe completarse sin excepción.
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Sin vínculos',
            'type' => 'technique',
        ]);

        $this->service->destroy($root);

        $this->assertDatabaseMissing('techniques', ['guid' => $root->guid]);
    }

    // -------------------------------------------------------------------------
    // regresión: countProtocolsForTechnique() ahora es una query real (ya no un stub)
    // -------------------------------------------------------------------------

    public function test_destroy_blocks_root_with_direct_protocols(): void
    {
        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Raíz con protocolos',
            'type' => 'technique',
        ]);

        // El protocolo cuelga directamente de la raíz (technique_id = $root->id),
        // caso de datos inconsistentes que igual debe bloquear el borrado.
        Protocol::create([
            'guid'            => Str::uuid()->toString(),
            'technique_id'    => $root->id,
            'created_by_type' => 'superadmin',
            'created_by_id'   => 1,
            'name'            => 'Protocolo directo',
        ]);

        $this->expectException(TechniqueCannotBeDeletedException::class);

        try {
            $this->service->destroy($root);
        } catch (TechniqueCannotBeDeletedException $e) {
            $this->assertEquals('has_protocols', $e->getReason());
            $this->assertEquals(1, $e->getCount());
            throw $e;
        }
    }
}
