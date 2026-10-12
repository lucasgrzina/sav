<?php

namespace Tests\Feature;

use App\Events\ProgramTargetsChangedEvent;
use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Program;
use App\Models\ProgramTarget;
use App\Models\Protocol;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * H1: a pending task_due alert whose scheduled_at already passed (the dispatcher has not
 * picked it up yet) must survive a program edit instead of being deleted and silently dropped.
 */
class GenerateProgramTaskDueAlertsListenerTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private Program $program;
    private ProtocolTaskAlert $protocolAlert;
    private UserProfile $owner;
    private UserProfile $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed clock: alerts are generated "in the future" and then the clock moves past them.
        Carbon::setTestNow('2030-06-01 10:00:00');

        $ownerRole   = Role::create(['guid' => Str::uuid()->toString(), 'name' => 'client-owner', 'guard_name' => 'web', 'type' => Role::TYPE_TENANT]);
        $managerRole = Role::create(['guid' => Str::uuid()->toString(), 'name' => 'client-manager', 'guard_name' => 'web', 'type' => Role::TYPE_TENANT]);

        $country  = Country::create(['guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54']);
        $docType  = DocumentType::create(['guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*']);
        $this->vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet', 'slug' => 'vet-' . Str::random(8),
            'country_id' => $country->id, 'document_type_id' => $docType->id, 'tax_id' => '20-11111111-9', 'validated_at' => now(),
        ]);
        $client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente', 'country_id' => $country->id,
            'document_type_id' => $docType->id, 'tax_id' => '20-22222222-1',
        ]);
        $client->vets()->attach($this->vet);
        $establishment = Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $client->id, 'name' => 'Estancia']);
        $technique     = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $protocol      = Protocol::create([
            'guid' => Str::uuid()->toString(), 'technique_id' => $technique->id, 'vet_id' => $this->vet->id,
            'created_by_type' => 'vet', 'created_by_id' => 1, 'name' => 'Protocolo',
        ]);
        $task = ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $protocol->id, 'description' => 'Tarea',
            'days_offset' => 0, 'time_of_day' => 'after', 'time' => '08:00', 'important' => false, 'sort_order' => 1,
        ]);
        $this->protocolAlert = ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id, 'offset_days' => 0,
            'time_of_day' => 'after', 'time' => '08:30', 'roles' => ['client-owner', 'client-manager'],
            'message' => 'Recordatorio', 'require_confirmation' => false, 'sort_order' => 1,
        ]);

        $this->owner   = $this->profile($client, $ownerRole);
        $this->manager = $this->profile($client, $managerRole);

        $this->program = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $this->vet->id, 'client_id' => $client->id,
            'establishment_id' => $establishment->id, 'technique_id' => $technique->id, 'protocol_id' => $protocol->id,
        ]);
        $this->program->managers()->sync([$this->owner->id, $this->manager->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overdue_pending_alert_survives_a_program_edit(): void
    {
        $this->addTarget('2030-06-10');
        $this->fireChanged();
        $alert = $this->taskDueAlerts()->firstOrFail();
        $recipientIds = $alert->recipients()->pluck('id')->all();

        Carbon::setTestNow('2030-06-20 12:00:00'); // scheduled_at passed, dispatcher has not run
        $this->fireChanged();

        $this->assertTrue(Alert::whereKey($alert->id)->where('status', 'pending')->exists());
        $this->assertEqualsCanonicalizing($recipientIds, AlertRecipient::where('alert_id', $alert->id)->pluck('id')->all());
        $this->assertSame(1, $this->taskDueAlerts()->count());
    }

    public function test_firing_the_event_twice_does_not_duplicate_alerts_or_recipients(): void
    {
        $this->addTarget('2030-06-10');
        $this->addTarget('2030-09-10');
        $this->fireChanged();

        Carbon::setTestNow('2030-06-20 12:00:00'); // first target overdue, second still in the future
        $this->fireChanged();
        $alerts = $this->taskDueAlerts()->count();
        $recipients = AlertRecipient::whereIn('alert_id', $this->taskDueAlerts()->pluck('id'))->count();

        $this->fireChanged();

        $this->assertSame(2, $alerts);
        $this->assertSame($alerts, $this->taskDueAlerts()->count());
        $this->assertSame($recipients, AlertRecipient::whereIn('alert_id', $this->taskDueAlerts()->pluck('id'))->count());
    }

    public function test_overdue_alert_drops_recipients_that_are_no_longer_managers_but_keeps_the_rest(): void
    {
        $this->addTarget('2030-06-10');
        $this->fireChanged();
        $alert = $this->taskDueAlerts()->firstOrFail();

        Carbon::setTestNow('2030-06-20 12:00:00');
        $this->program->managers()->detach($this->owner->id);
        $this->fireChanged();

        $this->assertTrue(Alert::whereKey($alert->id)->exists());
        $this->assertSame(
            [$this->manager->id],
            AlertRecipient::where('alert_id', $alert->id)->pluck('user_profile_id')->unique()->values()->all(),
        );
    }

    public function test_overdue_alert_that_is_no_longer_valid_is_removed(): void
    {
        $target = $this->addTarget('2030-06-10');
        $this->fireChanged();
        $this->assertSame(1, $this->taskDueAlerts()->count());

        Carbon::setTestNow('2030-06-20 12:00:00');
        $target->delete(); // the target the alert belonged to no longer exists
        $this->fireChanged();

        $this->assertSame(0, $this->taskDueAlerts()->count());
    }

    public function test_dispatched_alerts_are_never_touched(): void
    {
        $this->addTarget('2030-06-10');
        $this->fireChanged();
        $alert = $this->taskDueAlerts()->firstOrFail();
        $alert->update(['status' => 'dispatched']);

        Carbon::setTestNow('2030-06-20 12:00:00');
        $this->fireChanged();

        $this->assertSame('dispatched', $alert->fresh()->status);
        $this->assertSame(1, $this->taskDueAlerts()->count());
    }

    private function profile(Client $client, Role $role): UserProfile
    {
        return UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => User::factory()->create()->id,
            'authenticatable_type' => 'client', 'authenticatable_id' => $client->id, 'role_id' => $role->id,
        ]);
    }

    private function addTarget(string $date): ProgramTarget
    {
        return ProgramTarget::create(['program_id' => $this->program->id, 'target_date' => $date]);
    }

    private function fireChanged(): void
    {
        event(new ProgramTargetsChangedEvent(Program::findOrFail($this->program->id)));
    }

    private function taskDueAlerts()
    {
        return Alert::where('type', AlertType::ProgramTaskDue)
            ->where('subject_type', 'program')
            ->where('subject_id', $this->program->id);
    }
}
