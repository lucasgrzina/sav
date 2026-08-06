<?php

namespace Tests\Unit\Notifications;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Protocol;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Enums\AlertType;
use App\Repositories\AnimalRepositoryEloquent;
use App\Repositories\ProgramRepositoryEloquent;
use App\Services\ProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramAlertGenerationTest extends TestCase
{
    use RefreshDatabase;

    private ProgramService $service;
    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private Protocol $protocol;
    private Technique $technique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProgramService(new ProgramRepositoryEloquent(), new AnimalRepositoryEloquent());

        $this->vet = $this->createVet();
        $this->client = $this->createClient();

        $this->establishment = Establishment::create([
            'guid' => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name' => 'Establecimiento Test',
        ]);

        $root = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $this->technique = Technique::create([
            'guid' => Str::uuid()->toString(), 'name' => 'IATF', 'type' => 'technique', 'parent_id' => $root->id,
        ]);

        $this->protocol = Protocol::create([
            'guid' => Str::uuid()->toString(),
            'technique_id' => $this->technique->id,
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

    private function createManagerProfile(string $roleName = 'vet-manager'): UserProfile
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::factory()->create();

        return UserProfile::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $this->vet->id,
            'role_id' => $role->id,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'establishment_id' => $this->establishment->id,
            'protocol_id' => $this->protocol->id,
            'comments' => null,
            'targets' => [
                ['target_date' => '2026-08-15', 'animals' => []],
            ],
            'manager_profile_ids' => [$this->createManagerProfile()->id],
        ], $overrides);
    }

    public function test_creating_a_program_schedules_an_immediate_program_created_alert_for_every_manager(): void
    {
        $managerA = $this->createManagerProfile();
        $managerB = $this->createManagerProfile();

        $program = $this->service->create(
            $this->basePayload(['manager_profile_ids' => [$managerA->id, $managerB->id]]),
            $this->vet->id,
        );

        $alert = \App\Notifications\Models\Alert::where('type', AlertType::ProgramCreated)->firstOrFail();

        $this->assertSame('program', $alert->subject_type);
        $this->assertSame($program->id, $alert->subject_id);
        $this->assertTrue($alert->scheduled_at->lessThanOrEqualTo(now()));
        $this->assertEqualsCanonicalizing(
            [$managerA->id, $managerB->id],
            $alert->recipients->pluck('user_profile_id')->all(),
        );
    }

    public function test_creating_a_program_generates_one_task_due_alert_per_protocol_task_alert(): void
    {
        $task = ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $this->protocol->id,
            'description' => 'Tarea', 'days_offset' => 3, 'time_of_day' => 'before',
            'time' => '08:00', 'important' => false, 'sort_order' => 1,
        ]);

        $protocolTaskAlert = ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id,
            'offset_days' => 1, 'time_of_day' => 'after', 'time' => '08:30',
            'roles' => ['vet-manager'], 'message' => 'Recordatorio de tarea',
            'require_confirmation' => true, 'sort_order' => 1,
        ]);

        $program = $this->service->create($this->basePayload(), $this->vet->id);

        $alert = \App\Notifications\Models\Alert::where('type', AlertType::ProgramTaskDue)->firstOrFail();

        // target 2026-08-15 -3 (before) = 08-12, then +1 (after) = 08-13, at 08:30
        $this->assertSame('2026-08-13 08:30:00', $alert->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($protocolTaskAlert->guid, $alert->payload['protocolTaskAlertGuid']);
        $this->assertSame('Recordatorio de tarea', $alert->payload['message']);
        $this->assertTrue($alert->require_confirmation);
        $this->assertSame($program->id, $alert->subject_id);
    }

    public function test_task_due_alert_only_goes_to_managers_with_a_matching_role(): void
    {
        $matchingManager = $this->createManagerProfile('vet-assistant');
        $otherManager = $this->createManagerProfile('client-owner');

        $task = ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $this->protocol->id,
            'description' => 'Tarea', 'days_offset' => 0, 'time_of_day' => 'after',
            'time' => '08:00', 'important' => false, 'sort_order' => 1,
        ]);

        ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id,
            'offset_days' => 0, 'time_of_day' => 'after', 'time' => '08:00',
            'roles' => ['vet-assistant'], 'message' => 'Solo vet-assistant',
            'require_confirmation' => false, 'sort_order' => 1,
        ]);

        $this->service->create($this->basePayload([
            'manager_profile_ids' => [$matchingManager->id, $otherManager->id],
        ]), $this->vet->id);

        $alert = \App\Notifications\Models\Alert::where('type', AlertType::ProgramTaskDue)->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$matchingManager->id],
            $alert->recipients->pluck('user_profile_id')->all(),
        );
    }

    public function test_task_due_alert_is_silently_discarded_when_its_computed_date_already_passed(): void
    {
        $task = ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $this->protocol->id,
            'description' => 'Tarea vencida', 'days_offset' => 0, 'time_of_day' => 'before',
            'time' => '08:00', 'important' => false, 'sort_order' => 1,
        ]);

        ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id,
            'offset_days' => 0, 'time_of_day' => 'before', 'time' => '08:00',
            'roles' => ['vet-manager'], 'message' => 'Ya paso',
            'require_confirmation' => false, 'sort_order' => 1,
        ]);

        // Target date in the past relative to today (2026-07-24 per the session clock).
        $this->service->create($this->basePayload([
            'targets' => [['target_date' => '2026-01-01', 'animals' => []]],
        ]), $this->vet->id);

        $this->assertSame(0, \App\Notifications\Models\Alert::where('type', AlertType::ProgramTaskDue)->count());
    }

    public function test_editing_a_program_regenerates_pending_task_due_alerts(): void
    {
        $task = ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $this->protocol->id,
            'description' => 'Tarea', 'days_offset' => 0, 'time_of_day' => 'after',
            'time' => '08:00', 'important' => false, 'sort_order' => 1,
        ]);

        ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id,
            'offset_days' => 0, 'time_of_day' => 'after', 'time' => '08:00',
            'roles' => ['vet-manager'], 'message' => 'Recordatorio', 'require_confirmation' => false, 'sort_order' => 1,
        ]);

        $program = $this->service->create($this->basePayload([
            'targets' => [['target_date' => '2026-08-15', 'animals' => []]],
        ]), $this->vet->id);

        $originalAlertId = \App\Notifications\Models\Alert::where('type', AlertType::ProgramTaskDue)->firstOrFail()->id;

        $this->service->update($program, $this->basePayload([
            'targets' => [['target_date' => '2026-09-20', 'animals' => []]],
        ]));

        $this->assertDatabaseMissing('alerts', ['id' => $originalAlertId]);

        $regenerated = \App\Notifications\Models\Alert::where('type', AlertType::ProgramTaskDue)->firstOrFail();
        $this->assertSame('2026-09-20 08:00:00', $regenerated->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_cancelling_a_program_deletes_pending_task_due_alerts_and_schedules_an_immediate_cancelled_alert(): void
    {
        $task = ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $this->protocol->id,
            'description' => 'Tarea', 'days_offset' => 0, 'time_of_day' => 'after',
            'time' => '08:00', 'important' => false, 'sort_order' => 1,
        ]);

        ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id,
            'offset_days' => 0, 'time_of_day' => 'after', 'time' => '08:00',
            'roles' => ['vet-manager'], 'message' => 'Recordatorio', 'require_confirmation' => false, 'sort_order' => 1,
        ]);

        $program = $this->service->create($this->basePayload(), $this->vet->id);

        $this->assertSame(1, \App\Notifications\Models\Alert::where('type', AlertType::ProgramTaskDue)->count());

        $this->service->cancel($program);

        $this->assertSame(0, \App\Notifications\Models\Alert::where('type', AlertType::ProgramTaskDue)->count());

        $cancelledAlert = \App\Notifications\Models\Alert::where('type', AlertType::ProgramCancelled)->firstOrFail();
        $this->assertTrue($cancelledAlert->scheduled_at->lessThanOrEqualTo(now()));
        $this->assertGreaterThan(0, $cancelledAlert->recipients->count());
    }
}
