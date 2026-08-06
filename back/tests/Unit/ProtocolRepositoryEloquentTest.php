<?php

namespace Tests\Unit;

use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Protocol;
use App\Models\Technique;
use App\Models\Vet;
use App\Repositories\ProtocolRepositoryEloquent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProtocolRepositoryEloquentTest extends TestCase
{
    use RefreshDatabase;

    private ProtocolRepositoryEloquent $repository;
    private Technique $subTechnique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ProtocolRepositoryEloquent();

        $root = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Raíz',
            'type' => 'technique',
        ]);

        $this->subTechnique = Technique::create([
            'guid'      => Str::uuid()->toString(),
            'name'      => 'Sub-técnica',
            'type'      => 'technique',
            'parent_id' => $root->id,
        ]);
    }

    private function createProtocol(?int $countryId, string $name, ?int $vetId = null): Protocol
    {
        return Protocol::create([
            'guid'            => Str::uuid()->toString(),
            'technique_id'    => $this->subTechnique->id,
            'country_id'      => $countryId,
            'vet_id'          => $vetId,
            'created_by_type' => $vetId !== null ? 'vet' : 'superadmin',
            'created_by_id'   => 1,
            'name'            => $name,
        ]);
    }

    private function createVet(string $name = 'Vet Test'): Vet
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'A' . Str::random(1),
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid'              => Str::uuid()->toString(),
            'country_id'        => $country->id,
            'name'              => 'CUIT',
            'validation_regex'  => '.*',
        ]);

        return Vet::create([
            'guid'                => Str::uuid()->toString(),
            'name'                => $name,
            'slug'                => Str::slug($name) . '-' . Str::random(6),
            'country_id'          => $country->id,
            'document_type_id'    => $documentType->id,
            'tax_id'              => '20-12345678-9',
            'validated_at'        => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // existsDuplicate — caso country_id NULL (el índice DB solo no lo cubre, DEC-01)
    // -------------------------------------------------------------------------

    public function test_exists_duplicate_detects_duplicate_with_null_country(): void
    {
        $this->createProtocol(null, 'Protocolo global');

        $this->assertTrue(
            $this->repository->existsDuplicate($this->subTechnique->id, null, 'Protocolo global')
        );
    }

    public function test_exists_duplicate_returns_false_for_different_name_with_null_country(): void
    {
        $this->createProtocol(null, 'Protocolo global');

        $this->assertFalse(
            $this->repository->existsDuplicate($this->subTechnique->id, null, 'Otro nombre')
        );
    }

    // -------------------------------------------------------------------------
    // existsDuplicate — caso country_id con valor
    // -------------------------------------------------------------------------

    public function test_exists_duplicate_detects_duplicate_with_country_id(): void
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'AR',
            'phone_prefix' => '+54',
        ]);

        $this->createProtocol($country->id, 'Protocolo AR');

        $this->assertTrue(
            $this->repository->existsDuplicate($this->subTechnique->id, $country->id, 'Protocolo AR')
        );
    }

    public function test_exists_duplicate_does_not_confuse_null_and_non_null_country(): void
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'AR',
            'phone_prefix' => '+54',
        ]);

        $this->createProtocol(null, 'Protocolo mismo nombre');

        // Mismo nombre pero country_id distinto (uno explícito, otro null) → no es duplicado
        $this->assertFalse(
            $this->repository->existsDuplicate($this->subTechnique->id, $country->id, 'Protocolo mismo nombre')
        );
    }

    public function test_exists_duplicate_excludes_own_guid_on_update(): void
    {
        $protocol = $this->createProtocol(null, 'Protocolo a editar');

        $this->assertFalse(
            $this->repository->existsDuplicate($this->subTechnique->id, null, 'Protocolo a editar', excludeGuid: $protocol->guid)
        );
    }

    // -------------------------------------------------------------------------
    // existsDuplicate — caso vet_id (DEC-09): dos vets distintas pueden compartir nombre
    // -------------------------------------------------------------------------

    public function test_exists_duplicate_with_vet_id_does_not_collide_with_another_vet(): void
    {
        $vetA = $this->createVet('Vet A');
        $vetB = $this->createVet('Vet B');

        $this->createProtocol(null, 'Protocolo homónimo', $vetA->id);

        $this->assertFalse(
            $this->repository->existsDuplicate($this->subTechnique->id, null, 'Protocolo homónimo', $vetB->id)
        );
    }

    public function test_exists_duplicate_with_vet_id_detects_duplicate_within_same_vet(): void
    {
        $vet = $this->createVet();
        $this->createProtocol(null, 'Protocolo propio', $vet->id);

        $this->assertTrue(
            $this->repository->existsDuplicate($this->subTechnique->id, null, 'Protocolo propio', $vet->id)
        );
    }

    public function test_exists_duplicate_with_vet_id_does_not_confuse_global_and_own(): void
    {
        $vet = $this->createVet();
        $this->createProtocol(null, 'Protocolo global mismo nombre'); // vet_id null

        $this->assertFalse(
            $this->repository->existsDuplicate($this->subTechnique->id, null, 'Protocolo global mismo nombre', $vet->id)
        );
    }

    // -------------------------------------------------------------------------
    // paginateByRootTechnique — regresión DEC-08: NO debe incluir protocolos de vet
    // -------------------------------------------------------------------------

    public function test_paginate_by_root_technique_excludes_vet_protocols(): void
    {
        $root = $this->subTechnique->parent()->first() ?? Technique::find($this->subTechnique->parent_id);
        $vet = $this->createVet();

        $this->createProtocol(null, 'Protocolo global');
        $this->createProtocol(null, 'Protocolo de vet', $vet->id);

        $result = $this->repository->paginateByRootTechnique(
            Technique::find($this->subTechnique->parent_id),
            [],
            15,
        );

        $this->assertCount(1, $result->items());
        $this->assertEquals('Protocolo global', $result->items()[0]->name);
    }

    // -------------------------------------------------------------------------
    // paginateForVet
    // -------------------------------------------------------------------------

    public function test_paginate_for_vet_mixes_globals_and_own(): void
    {
        $vet = $this->createVet();

        $this->createProtocol(null, 'Protocolo global');
        $this->createProtocol(null, 'Protocolo propio', $vet->id);

        $result = $this->repository->paginateForVet($vet->id, [], 15);

        $this->assertCount(2, $result->items());
    }

    public function test_paginate_for_vet_excludes_other_vet_protocols(): void
    {
        $vetA = $this->createVet('Vet A');
        $vetB = $this->createVet('Vet B');

        $this->createProtocol(null, 'Protocolo de otra vet', $vetB->id);

        $result = $this->repository->paginateForVet($vetA->id, [], 15);

        $this->assertCount(0, $result->items());
    }

    // -------------------------------------------------------------------------
    // findByGuidForVet
    // -------------------------------------------------------------------------

    public function test_find_by_guid_for_vet_returns_null_for_global_protocol(): void
    {
        $vet = $this->createVet();
        $protocol = $this->createProtocol(null, 'Protocolo global');

        $this->assertNull($this->repository->findByGuidForVet($protocol->guid, $vet->id));
    }

    public function test_find_by_guid_for_vet_returns_null_for_other_vet_protocol(): void
    {
        $vetA = $this->createVet('Vet A');
        $vetB = $this->createVet('Vet B');
        $protocol = $this->createProtocol(null, 'Protocolo de vet B', $vetB->id);

        $this->assertNull($this->repository->findByGuidForVet($protocol->guid, $vetA->id));
    }

    public function test_find_by_guid_for_vet_returns_own_protocol(): void
    {
        $vet = $this->createVet();
        $protocol = $this->createProtocol(null, 'Protocolo propio', $vet->id);

        $found = $this->repository->findByGuidForVet($protocol->guid, $vet->id);

        $this->assertNotNull($found);
        $this->assertEquals($protocol->guid, $found->guid);
    }
}
