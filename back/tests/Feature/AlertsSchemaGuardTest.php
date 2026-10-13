<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Support\AlertsSchemaGuard;
use App\Services\ProgramAlerts\ProgramAlertReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsProgramAlertFixture;
use Tests\TestCase;

/** Deploy-order guard: the code may go live before the hand-applied Fase 3 SQL scripts (SQL-01/SQL-02). */
class AlertsSchemaGuardTest extends TestCase
{
    use BuildsProgramAlertFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-06-01 10:00:00');
        $this->buildProgramFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guard_is_ready_with_the_full_schema(): void
    {
        $guard = app(AlertsSchemaGuard::class);

        $this->assertSame([], $guard->missing());
        $this->assertTrue($guard->isReady());
    }

    public function test_guard_names_missing_tables_and_columns_and_recovers_once_they_exist(): void
    {
        $guard = new AlertsSchemaGuard(['alerts' => ['logical_key', 'nope_col']], ['nope_table']);

        $this->assertFalse($guard->isReady());
        $this->assertSame(['table nope_table', 'alerts.nope_col'], $guard->missing());

        $ready = new AlertsSchemaGuard(['alerts' => ['logical_key']], ['program_alert_reconciliations']);
        $this->assertTrue($ready->isReady());
        $this->assertTrue($ready->isReady()); // memoized
    }

    public function test_guard_detects_a_missing_reconciliations_table(): void
    {
        $this->dropReconciliationsTable();

        $this->assertSame(['table program_alert_reconciliations'], app(AlertsSchemaGuard::class)->missing());
    }

    public function test_dispatch_due_leaves_alerts_pending_and_fails_loudly_when_the_schema_is_outdated(): void
    {
        Queue::fake();
        [$alert] = $this->dueAlertWithPendingRecipient();
        $this->dropReconciliationsTable();

        $this->artisan('alerts:dispatch-due')->assertFailed();

        $this->assertSame('pending', $alert->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_schema_check_command_reports_the_missing_pieces(): void
    {
        $this->artisan('alerts:schema-check')->assertSuccessful();

        $this->dropReconciliationsTable();

        $this->artisan('alerts:schema-check')
            ->expectsOutputToContain('program_alert_reconciliations')
            ->assertFailed();
    }

    public function test_job_postpones_without_touching_the_recipient_when_the_schema_is_outdated(): void
    {
        [, $recipient] = $this->dueAlertWithPendingRecipient('dispatched');
        $this->dropReconciliationsTable();

        $job = (new DeliverAlertJob($recipient->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(300);
        $this->assertSame(DeliveryStatus::Pending, $recipient->fresh()->status);
        $this->assertNull($recipient->fresh()->destination_hint);
    }

    public function test_failed_hook_leaves_the_recipient_pending_and_does_not_throw_when_the_schema_is_outdated(): void
    {
        [, $recipient] = $this->dueAlertWithPendingRecipient('dispatched');
        $this->dropReconciliationsTable();

        (new DeliverAlertJob($recipient->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(DeliveryStatus::Pending, $recipient->fresh()->status);
    }

    public function test_failed_hook_never_throws_even_if_recording_the_failure_blows_up(): void
    {
        [, $recipient] = $this->dueAlertWithPendingRecipient('dispatched');
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'update "alert_recipients"')) {
                throw new \RuntimeException('unknown column');
            }
        });

        Log::spy();

        (new DeliverAlertJob($recipient->id))->failed(new \RuntimeException('boom'));

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_reconciler_skips_instead_of_throwing_when_the_schema_is_outdated(): void
    {
        $this->program = $this->programWithTarget();
        $this->dropReconciliationsTable();

        $report = app(ProgramAlertReconciler::class)->reconcile(Program::findOrFail($this->program->id), 'program_created');

        $this->assertTrue($report->aborted);
        $this->assertSame('schema_outdated', $report->abortReason);
        $this->assertSame(0, Alert::where('type', AlertType::ProgramTaskDue)->count());
    }

    private Program $program;

    /** Simulates SQL-02 not applied. The guard memoizes a positive result, so the container one is reset too. */
    private function dropReconciliationsTable(): void
    {
        Schema::drop('program_alert_reconciliations');
        app()->forgetInstance(AlertsSchemaGuard::class);
    }

    private function programWithTarget(): Program
    {
        $owner = $this->makeResponsible();

        return $this->makeProgram(['2030-06-10'], [$owner]);
    }

    /** @return array{Alert, AlertRecipient} an alert already due, with one pending recipient */
    private function dueAlertWithPendingRecipient(string $status = 'pending'): array
    {
        $this->program = $this->programWithTarget();
        app(ProgramAlertReconciler::class)->reconcile(Program::findOrFail($this->program->id), 'program_created');

        $alert = Alert::where('type', AlertType::ProgramTaskDue)->firstOrFail();
        $alert->update(['status' => $status, 'scheduled_at' => Carbon::now()->subMinute()]);
        $recipient = AlertRecipient::where('alert_id', $alert->id)->firstOrFail();

        return [$alert->fresh(), $recipient];
    }
}
