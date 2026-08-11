<?php

namespace Tests\Unit;

use App\Exports\Programs\ProgramPdfExporter;
use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Program;
use App\Models\Protocol;
use App\Models\ProtocolTask;
use App\Models\Technique;
use App\Models\Vet;
use App\Repositories\AnimalRepositoryEloquent;
use App\Repositories\ProgramRepositoryEloquent;
use App\Services\ProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramPdfExporterTest extends TestCase
{
    use RefreshDatabase;

    private ProgramPdfExporter $exporter;
    private ProgramService $programService;
    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private Protocol $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->programService = new ProgramService(new ProgramRepositoryEloquent(), new AnimalRepositoryEloquent());
        $this->exporter = new ProgramPdfExporter(new ProgramRepositoryEloquent(), $this->programService);

        $this->vet = $this->createVet();
        $this->client = $this->createClient();

        $this->establishment = Establishment::create([
            'guid' => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name' => 'Establecimiento Test',
        ]);

        $root = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $technique = Technique::create([
            'guid' => Str::uuid()->toString(), 'name' => 'IATF', 'type' => 'technique', 'parent_id' => $root->id,
        ]);

        $this->protocol = Protocol::create([
            'guid' => Str::uuid()->toString(),
            'technique_id' => $technique->id,
            'vet_id' => $this->vet->id,
            'created_by_type' => 'vet',
            'created_by_id' => 1,
            'name' => 'Protocolo Test',
        ]);
    }

    private function createVet(): Vet
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina',
            'iso_code' => 'A' . Str::random(4), 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id,
            'name' => 'CUIT', 'validation_regex' => '.*',
        ]);

        return Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet Test',
            'slug' => 'vet-test-' . Str::random(6), 'country_id' => $country->id,
            'document_type_id' => $documentType->id, 'tax_id' => '20-12345678-9', 'validated_at' => now(),
        ]);
    }

    private function createClient(): Client
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina',
            'iso_code' => 'A' . Str::random(4), 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id,
            'name' => 'CUIT', 'validation_regex' => '.*',
        ]);

        return Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente Test',
            'country_id' => $country->id, 'document_type_id' => $documentType->id,
            'tax_id' => '20-' . Str::random(8) . '-9',
        ]);
    }

    private function createProgram(): Program
    {
        return $this->programService->create([
            'client_id' => $this->client->id,
            'establishment_id' => $this->establishment->id,
            'protocol_id' => $this->protocol->id,
            'comments' => null,
            'targets' => [
                ['target_date' => '2026-08-01', 'animals' => []],
                ['target_date' => '2026-09-01', 'animals' => []],
            ],
            'manager_profile_ids' => [],
        ], $this->vet->id);
    }

    public function test_export_generates_pdf_file_for_a_program_with_two_targets(): void
    {
        ProtocolTask::create([
            'guid' => Str::uuid()->toString(),
            'protocol_id' => $this->protocol->id,
            'description' => 'Tarea de prueba',
            'days_offset' => 0,
            'time_of_day' => 'after',
            'time' => '08:00',
            'important' => false,
            'sort_order' => 1,
        ]);

        $program = $this->createProgram();
        $filePath = 'exports/test/program-detail.pdf';

        $result = $this->exporter->export(
            ['program_guid' => $program->guid, 'vet_id' => $this->vet->id],
            [],
            $filePath,
        );

        $this->assertSame($filePath, $result);
        Storage::disk('local')->assertExists($filePath);
    }

    public function test_export_does_not_fail_when_protocol_has_no_tasks(): void
    {
        $program = $this->createProgram();
        $filePath = 'exports/test/program-detail-empty.pdf';

        $this->exporter->export(
            ['program_guid' => $program->guid, 'vet_id' => $this->vet->id],
            [],
            $filePath,
        );

        Storage::disk('local')->assertExists($filePath);
    }

    public function test_export_throws_when_program_does_not_belong_to_vet(): void
    {
        $program = $this->createProgram();
        $otherVet = $this->createVet();

        $this->expectException(\RuntimeException::class);

        $this->exporter->export(
            ['program_guid' => $program->guid, 'vet_id' => $otherVet->id],
            [],
            'exports/test/should-not-exist.pdf',
        );
    }
}
