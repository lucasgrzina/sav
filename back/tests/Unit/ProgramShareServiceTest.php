<?php

namespace Tests\Unit;

use App\Contracts\Repositories\ExportRepositoryInterface;
use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Program;
use App\Models\Protocol;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Repositories\AnimalRepositoryEloquent;
use App\Repositories\ProgramRepositoryEloquent;
use App\Services\Exports\ExportService;
use App\Services\ProgramService;
use App\Services\ProgramShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramShareServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProgramShareService $service;
    private ProgramService $programService;
    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private Protocol $protocol;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programService = new ProgramService(new ProgramRepositoryEloquent(), new AnimalRepositoryEloquent());
        $this->service = new ProgramShareService(
            new ExportService(app(ExportRepositoryInterface::class), app(\App\Contracts\Exports\ExportResolverInterface::class)),
        );

        $this->vet = $this->createVet();
        $this->client = $this->createClient();
        $this->user = User::factory()->create(['guid' => Str::uuid()->toString()]);

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

    private function createManagerWithRole(string $roleName, string $authenticatableType = 'vet'): UserProfile
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::factory()->create(['guid' => Str::uuid()->toString()]);
        $authenticatableId = $authenticatableType === 'vet' ? $this->vet->id : $this->client->id;

        return UserProfile::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'authenticatable_type' => $authenticatableType,
            'authenticatable_id' => $authenticatableId,
            'role_id' => $role->id,
        ]);
    }

    private function createProgram(array $managerProfileIds = []): Program
    {
        return $this->programService->create([
            'client_id' => $this->client->id,
            'establishment_id' => $this->establishment->id,
            'protocol_id' => $this->protocol->id,
            'comments' => null,
            'targets' => [
                ['target_date' => '2026-08-01', 'animals' => []],
            ],
            'manager_profile_ids' => $managerProfileIds,
        ], $this->vet->id);
    }

    // -------------------------------------------------------------------------
    // listClientRecipients
    // -------------------------------------------------------------------------

    public function test_list_client_recipients_excluye_roles_vet(): void
    {
        $vetManager = $this->createManagerWithRole('vet', 'vet');
        $clientOwner = $this->createManagerWithRole('client-owner', 'client');

        $program = $this->createProgram([$vetManager->id, $clientOwner->id]);

        $recipients = $this->service->listClientRecipients($program);

        $this->assertCount(1, $recipients);
        $this->assertEquals($clientOwner->guid, $recipients->first()->guid);
    }

    public function test_list_client_recipients_has_whatsapp_false_sin_contacto_primario(): void
    {
        $clientOwner = $this->createManagerWithRole('client-owner', 'client');
        $program = $this->createProgram([$clientOwner->id]);

        $recipients = $this->service->listClientRecipients($program);

        $this->assertFalse($recipients->first()->has_whatsapp);
    }

    public function test_list_client_recipients_has_whatsapp_true_con_contacto_habilitado(): void
    {
        $clientOwner = $this->createManagerWithRole('client-owner', 'client');
        Contact::create([
            'contactable_type' => 'user_profile',
            'contactable_id' => $clientOwner->id,
            'type' => 'whatsapp',
            'label' => 'Principal',
            'value' => '5491122334455',
            'is_primary' => true,
            'use_for_alerts' => true,
        ]);

        $program = $this->createProgram([$clientOwner->id]);

        $recipients = $this->service->listClientRecipients($program);

        $this->assertTrue($recipients->first()->has_whatsapp);
    }

    // -------------------------------------------------------------------------
    // sendPdfToRecipients
    // -------------------------------------------------------------------------

    public function test_send_pdf_to_recipients_crea_alert_y_recipients_y_despacha_job(): void
    {
        Bus::fake();

        $clientOwnerA = $this->createManagerWithRole('client-owner', 'client');
        $clientOwnerB = $this->createManagerWithRole('client-manager', 'client');
        $vetManager = $this->createManagerWithRole('vet', 'vet');

        $program = $this->createProgram([$clientOwnerA->id, $clientOwnerB->id, $vetManager->id]);

        $export = \App\Models\Export::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $this->user->id,
            'type' => ExportType::PROGRAM,
            'format' => ExportFormat::PDF,
            'status' => ExportStatus::COMPLETED,
            'file_path' => 'exports/test/program.pdf',
            'file_name' => 'program.pdf',
            'filters' => ['program_guid' => $program->guid, 'vet_id' => $this->vet->id],
            'expires_at' => now()->addDays(7),
        ]);

        $alert = $this->service->sendPdfToRecipients(
            $program,
            $export,
            [$clientOwnerA->guid, $clientOwnerB->guid],
            $this->vet->id,
        );

        $this->assertInstanceOf(Alert::class, $alert);
        $this->assertEquals('dispatched', $alert->fresh()->status);
        $this->assertEquals(2, $alert->recipients()->count());

        Bus::assertDispatchedTimes(DeliverAlertJob::class, 2);
    }

    public function test_send_pdf_to_recipients_ignora_guids_que_no_son_client_staff(): void
    {
        Bus::fake();

        $clientOwner = $this->createManagerWithRole('client-owner', 'client');
        $vetManager = $this->createManagerWithRole('vet', 'vet');

        $program = $this->createProgram([$clientOwner->id, $vetManager->id]);

        $export = \App\Models\Export::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $this->user->id,
            'type' => ExportType::PROGRAM,
            'format' => ExportFormat::PDF,
            'status' => ExportStatus::COMPLETED,
            'file_path' => 'exports/test/program.pdf',
            'file_name' => 'program.pdf',
            'filters' => ['program_guid' => $program->guid, 'vet_id' => $this->vet->id],
            'expires_at' => now()->addDays(7),
        ]);

        $alert = $this->service->sendPdfToRecipients(
            $program,
            $export,
            [$clientOwner->guid, $vetManager->guid],
            $this->vet->id,
        );

        $this->assertEquals(1, $alert->recipients()->count());
        Bus::assertDispatchedTimes(DeliverAlertJob::class, 1);
    }
}
