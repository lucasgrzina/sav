<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Repositories\AnimalRepositoryEloquent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnimalRepositoryEloquentTest extends TestCase
{
    use RefreshDatabase;

    private AnimalRepositoryEloquent $repository;
    private Client $clientA;
    private Client $clientB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new AnimalRepositoryEloquent();
        $this->clientA = $this->createClient('Cliente A');
        $this->clientB = $this->createClient('Cliente B');
    }

    private function createClient(string $name): Client
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'A' . Str::random(2),
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

    // -------------------------------------------------------------------------
    // findManyByGuids
    // -------------------------------------------------------------------------

    public function test_find_many_by_guids_filters_correctly(): void
    {
        $animal1 = $this->repository->create(['client_id' => $this->clientA->id, 'rp' => 'A1', 'type' => 'livestock']);
        $animal2 = $this->repository->create(['client_id' => $this->clientA->id, 'rp' => 'A2', 'type' => 'livestock']);
        $this->repository->create(['client_id' => $this->clientA->id, 'rp' => 'A3', 'type' => 'livestock']);

        $result = $this->repository->findManyByGuids([$animal1->guid, $animal2->guid]);

        $this->assertCount(2, $result);
        $this->assertEqualsCanonicalizing(['A1', 'A2'], $result->pluck('rp')->all());
    }

    // -------------------------------------------------------------------------
    // searchForClient
    // -------------------------------------------------------------------------

    public function test_search_for_client_matches_by_rp(): void
    {
        $this->repository->create(['client_id' => $this->clientA->id, 'rp' => 'A123', 'type' => 'livestock']);
        $this->repository->create(['client_id' => $this->clientA->id, 'rp' => 'B456', 'type' => 'livestock']);

        $result = $this->repository->searchForClient($this->clientA->id, 'A12');

        $this->assertCount(1, $result);
        $this->assertEquals('A123', $result->first()->rp);
    }

    public function test_search_for_client_matches_by_name(): void
    {
        $this->repository->create(['client_id' => $this->clientA->id, 'rp' => 'A123', 'name' => 'Vaca Lola', 'type' => 'livestock']);

        $result = $this->repository->searchForClient($this->clientA->id, 'Lola');

        $this->assertCount(1, $result);
        $this->assertEquals('A123', $result->first()->rp);
    }

    public function test_search_for_client_never_returns_animal_from_other_client_even_with_matching_rp(): void
    {
        $this->repository->create(['client_id' => $this->clientA->id, 'rp' => 'SAME', 'type' => 'livestock']);
        $this->repository->create(['client_id' => $this->clientB->id, 'rp' => 'SAME', 'type' => 'livestock']);

        $result = $this->repository->searchForClient($this->clientA->id, 'SAME');

        $this->assertCount(1, $result);
        $this->assertEquals($this->clientA->id, $result->first()->client_id);
    }

    // -------------------------------------------------------------------------
    // firstOrCreateForClient
    // -------------------------------------------------------------------------

    public function test_first_or_create_for_client_reuses_existing_row_of_same_client(): void
    {
        $existing = $this->repository->firstOrCreateForClient($this->clientA->id, 'RP1');
        $again    = $this->repository->firstOrCreateForClient($this->clientA->id, 'RP1');

        $this->assertEquals($existing->id, $again->id);
        $this->assertEquals(1, \App\Models\Animal::where('client_id', $this->clientA->id)->where('rp', 'RP1')->count());
    }

    public function test_first_or_create_for_client_creates_new_row_when_rp_belongs_to_another_client(): void
    {
        $animalFromClientA = $this->repository->firstOrCreateForClient($this->clientA->id, 'SHARED');
        $animalFromClientB = $this->repository->firstOrCreateForClient($this->clientB->id, 'SHARED');

        $this->assertNotEquals($animalFromClientA->id, $animalFromClientB->id);
        $this->assertEquals($this->clientA->id, $animalFromClientA->client_id);
        $this->assertEquals($this->clientB->id, $animalFromClientB->client_id);
    }

    // -------------------------------------------------------------------------
    // regresión de constraint: unique(client_id, rp)
    // -------------------------------------------------------------------------

    public function test_direct_create_with_duplicate_client_id_and_rp_throws_query_exception(): void
    {
        $this->repository->create(['client_id' => $this->clientA->id, 'rp' => 'DUP', 'type' => 'livestock']);

        $this->expectException(QueryException::class);

        $this->repository->create(['client_id' => $this->clientA->id, 'rp' => 'DUP', 'type' => 'livestock']);
    }
}
