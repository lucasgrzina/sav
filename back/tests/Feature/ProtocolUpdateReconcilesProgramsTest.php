<?php

namespace Tests\Feature;

use App\Jobs\ReconcileProgramAlertsJob;
use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Program;
use App\Models\ProgramAlertReconciliation;
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
use App\Services\ProgramAlerts\ProgramAlertReconciler;
use App\Services\ProtocolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** H4: editing a protocol propagates to the active programs using it; nightly command + scheduler. */
class ProtocolUpdateReconcilesProgramsTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private Technique $technique;
    private Protocol $protocol;
    private ProtocolTask $task;
    private ProtocolTaskAlert $protocolAlert;
    private Role $ownerRole;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2030-06-01 10:00:00');

        $this->ownerRole = Role::create(['guid' => Str::uuid()->toString(), 'name' => 'client-owner', 'guard_name' => 'web', 'type' => Role::TYPE_TENANT]);
        $country = Country::create(['guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54']);
        $docType = DocumentType::create(['guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*']);
        $this->vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet', 'slug' => 'vet-' . Str::random(8),
            'country_id' => $country->id, 'document_type_id' => $docType->id, 'tax_id' => '20-11111111-9', 'validated_at' => now(),
        ]);
        $this->client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente', 'country_id' => $country->id,
            'document_type_id' => $docType->id, 'tax_id' => '20-22222222-1',
        ]);
        $this->client->vets()->attach($this->vet);
        $this->establishment = Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $this->client->id, 'name' => 'Estancia']);
        $this->technique = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $this->protocol = Protocol::create([
            'guid' => Str::uuid()->toString(), 'technique_id' => $this->technique->id, 'vet_id' => $this->vet->id,
            'created_by_type' => 'vet', 'created_by_id' => 1, 'name' => 'Protocolo',
        ]);
        $this->task = ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $this->protocol->id, 'description' => 'Tarea original',
            'days_offset' => 0, 'time_of_day' => 'after', 'time' => '08:00', 'important' => false, 'sort_order' => 1,
        ]);
        $this->protocolAlert = ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $this->task->id, 'offset_days' => 0,
            'time_of_day' => 'after', 'time' => '08:30', 'roles' => ['client-owner'],
            'message' => 'Recordatorio', 'require_confirmation' => false, 'sort_order' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_update_dispatches_one_job_per_active_program_only(): void
    {
        $active1 = $this->makeProgram('2030-06-10');
        $active2 = $this->makeProgram('2030-06-11');
        $cancelled = $this->makeProgram('2030-06-12', cancelled: true);
        Queue::fake();

        $this->editTaskDescription('Nueva descripcion');

        Queue::assertPushed(ReconcileProgramAlertsJob::class, 2);
        Queue::assertPushed(ReconcileProgramAlertsJob::class, fn ($j) => $j->programId === $active1->id && $j->trigger === 'protocol_updated');
        Queue::assertPushed(ReconcileProgramAlertsJob::class, fn ($j) => $j->programId === $active2->id);
        Queue::assertNotPushed(ReconcileProgramAlertsJob::class, fn ($j) => $j->programId === $cancelled->id);
    }

    public function test_job_refreshes_pending_alerts_and_leaves_dispatched_untouched(): void
    {
        $program = $this->makeProgram('2030-06-10');
        $this->makeProgram('2030-09-10', program: $program);
        $this->reconcile($program);

        $alerts = $this->alertsOf($program)->orderBy('scheduled_at')->get();
        $this->assertCount(2, $alerts);
        [$pending, $dispatched] = [$alerts[1], $alerts[0]];
        Alert::whereKey($dispatched->id)->update(['status' => 'dispatched']);
        $dispatchedPayload = Alert::find($dispatched->id)->payload;

        Queue::fake(); // the edit only queues the job; run it explicitly below
        $this->editTaskDescription('Nueva descripcion');
        $this->runJob($program->id, 'protocol_updated');

        $this->assertSame('Nueva descripcion', Alert::find($pending->id)->payload['taskDescription']);
        $this->assertSame($dispatchedPayload, Alert::find($dispatched->id)->payload);
        $this->assertSame(1, ProgramAlertReconciliation::where('program_id', $program->id)->where('trigger', 'protocol_updated')->count());
    }

    public function test_job_skips_cancelled_programs_and_honours_the_kill_switch(): void
    {
        $program = $this->makeProgram('2030-06-10');
        $this->reconcile($program);
        $alert = $this->alertsOf($program)->firstOrFail();
        $before = $alert->payload;

        Queue::fake();
        $this->editTaskDescription('Nueva descripcion');

        config(['notifications.reconciler.enabled' => false]);
        $this->runJob($program->id, 'protocol_updated');
        $this->assertSame($before, Alert::find($alert->id)->payload);

        config(['notifications.reconciler.enabled' => true]);
        $program->update(['cancelled_at' => now()]);
        $this->runJob($program->id, 'protocol_updated');
        $this->assertSame($before, Alert::find($alert->id)->payload);
        $this->assertSame('pending', Alert::find($alert->id)->status);
    }

    public function test_job_logs_and_rethrows_on_failure(): void
    {
        $program = $this->makeProgram('2030-06-10');
        Queue::fake();
        $this->editTaskDescription('Nueva descripcion');
        Alert::creating(fn () => throw new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->runJob($program->id, 'protocol_updated');
    }

    public function test_command_dry_run_writes_nothing(): void
    {
        $program = $this->makeProgram('2030-06-10');

        $this->artisan('programs:reconcile-alerts', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $this->alertsOf($program)->count());
        $this->assertSame(0, ProgramAlertReconciliation::count());
    }

    public function test_command_stale_only_processes_only_programs_out_of_sync(): void
    {
        $inSync = $this->makeProgram('2030-06-10');
        $this->reconcile($inSync);
        $stale = $this->makeProgram('2030-06-11');

        $this->artisan('programs:reconcile-alerts', ['--stale-only' => true, '--trigger' => 'nightly'])
            ->assertSuccessful();

        $this->assertSame(1, $this->alertsOf($stale)->count());
        $this->assertSame(1, ProgramAlertReconciliation::where('program_id', $stale->id)->where('trigger', 'nightly')->count());
        $this->assertSame(0, ProgramAlertReconciliation::where('program_id', $inSync->id)->where('trigger', 'nightly')->count());
    }

    public function test_command_automatic_trigger_is_a_noop_when_reconciler_is_disabled(): void
    {
        $program = $this->makeProgram('2030-06-10');
        config(['notifications.reconciler.enabled' => false]);

        $this->artisan('programs:reconcile-alerts', ['--stale-only' => true, '--trigger' => 'nightly'])->assertSuccessful();

        $this->assertSame(0, $this->alertsOf($program)->count());
    }

    public function test_command_program_option_limits_scope(): void
    {
        $a = $this->makeProgram('2030-06-10');
        $b = $this->makeProgram('2030-06-11');

        $this->artisan('programs:reconcile-alerts', ['--program' => $a->guid])->assertSuccessful();

        $this->assertSame(1, $this->alertsOf($a)->count());
        $this->assertSame(0, $this->alertsOf($b)->count());
    }

    public function test_command_isolates_failures_per_program_and_exits_with_failure(): void
    {
        $bad = $this->makeProgram('2030-06-10');
        $good = $this->makeProgram('2030-06-11');
        Alert::creating(function (Alert $alert) use ($bad) {
            if ($alert->subject_id === $bad->id) {
                throw new \RuntimeException('boom');
            }
        });

        $this->artisan('programs:reconcile-alerts')->assertFailed();

        $this->assertSame(0, $this->alertsOf($bad)->count());
        $this->assertSame(1, $this->alertsOf($good)->count());
    }

    public function test_nightly_command_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'programs:reconcile-alerts'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--stale-only', $event->command);
        $this->assertStringContainsString('--trigger=nightly', $event->command);
    }

    public function test_active_programs_count_ignores_cancelled_and_other_protocols(): void
    {
        $this->makeProgram('2030-06-10');
        $this->makeProgram('2030-06-11', cancelled: true);
        $other = Protocol::create([
            'guid' => Str::uuid()->toString(), 'technique_id' => $this->technique->id, 'vet_id' => $this->vet->id,
            'created_by_type' => 'vet', 'created_by_id' => 1, 'name' => 'Otro',
        ]);
        Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $this->vet->id, 'client_id' => $this->client->id,
            'establishment_id' => $this->establishment->id, 'technique_id' => $this->technique->id, 'protocol_id' => $other->id,
        ]);

        $service = app(ProtocolService::class);

        $this->assertSame(1, $service->countActiveProgramsForProtocol($this->protocol));
        $this->assertSame(1, $service->countActiveProgramsForProtocol($other));
    }

    private function editTaskDescription(string $description): void
    {
        app(ProtocolService::class)->update($this->protocol->fresh(), [
            'name' => $this->protocol->name,
            'tasks' => [[
                'guid' => $this->task->guid,
                'description' => $description,
                'days_offset' => 0, 'time_of_day' => 'after', 'time' => '08:00', 'important' => false,
                'alerts' => [[
                    'guid' => $this->protocolAlert->guid, 'offset_days' => 0, 'time_of_day' => 'after',
                    'time' => '08:30', 'roles' => ['client-owner'], 'message' => 'Recordatorio',
                ]],
            ]],
        ]);
    }

    private function runJob(int $programId, string $trigger): void
    {
        // Queue is faked in these tests; run the handler directly.
        (new ReconcileProgramAlertsJob($programId, $trigger))->handle(app(ProgramAlertReconciler::class));
    }

    private function reconcile(Program $program): void
    {
        app(ProgramAlertReconciler::class)->reconcile($program, 'program_created');
    }

    private function alertsOf(Program $program)
    {
        return Alert::where('type', AlertType::ProgramTaskDue)->where('subject_type', 'program')->where('subject_id', $program->id);
    }

    /** Creates a program with one group at `$date`, or adds a group to `$program` when given. */
    private function makeProgram(string $date, bool $cancelled = false, ?Program $program = null): Program
    {
        if ($program === null) {
            $program = Program::create([
                'guid' => Str::uuid()->toString(), 'vet_id' => $this->vet->id, 'client_id' => $this->client->id,
                'establishment_id' => $this->establishment->id, 'technique_id' => $this->technique->id,
                'protocol_id' => $this->protocol->id, 'cancelled_at' => $cancelled ? now() : null,
            ]);
            $owner = UserProfile::create([
                'guid' => Str::uuid()->toString(), 'user_id' => User::factory()->create()->id,
                'authenticatable_type' => 'client', 'authenticatable_id' => $this->client->id, 'role_id' => $this->ownerRole->id,
            ]);
            $program->managers()->sync([$owner->id]);
        }

        ProgramTarget::create(['program_id' => $program->id, 'target_date' => $date]);

        return $program;
    }
}
