<?php

namespace Tests\Feature;

use App\Events\ProgramTargetsChangedEvent;
use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Contracts\Repositories\ProgramRepositoryInterface;
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
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Services\AlertRecipientFactory;
use App\Services\ProgramAlerts\Data\ReconcileReport;
use App\Services\ProgramAlerts\ProgramAlertReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * H1: a pending task_due alert whose scheduled_at already passed (the dispatcher has not
 * picked it up yet) must survive a program edit instead of being deleted and silently dropped.
 */
class ProgramAlertReconcilerTest extends TestCase
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

        $this->assertTrue(Alert::whereKey($alert->id)->where('status', 'pending')->exists());
        $this->assertSame(
            [$this->manager->id],
            AlertRecipient::where('alert_id', $alert->id)->where('status', 'pending')->pluck('user_profile_id')->unique()->values()->all(),
        );
        // Soft: the removed responsible keeps a suppressed row explaining why it was not notified.
        $removed = AlertRecipient::where('alert_id', $alert->id)->where('user_profile_id', $this->owner->id)->firstOrFail();
        $this->assertSame('suppressed', $removed->status->value);
        $this->assertSame('no_longer_applicable', $removed->failure_code->value);
    }

    public function test_overdue_alert_that_is_no_longer_valid_is_removed(): void
    {
        $target = $this->addTarget('2030-06-10');
        $this->fireChanged();
        $this->assertSame(1, $this->taskDueAlerts()->count());

        Carbon::setTestNow('2030-06-20 12:00:00');
        $target->delete(); // the target the alert belonged to no longer exists
        $this->fireChanged();

        $this->assertSame(0, $this->taskDueAlerts()->where('status', '!=', 'cancelled')->count());
        $cancelled = $this->taskDueAlerts()->where('status', 'cancelled')->firstOrFail();
        $this->assertSame('group_removed', $cancelled->cancel_reason->value);
        $this->assertNull($cancelled->logical_key);
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

    public function test_reconciling_twice_is_idempotent_and_keeps_alert_guids(): void
    {
        $this->addTarget('2030-06-10');
        $this->addTarget('2030-09-10');
        $first = $this->reconcile('program_created');
        $guids = $this->activeAlerts()->pluck('guid')->sort()->values()->all();

        $plan = $this->reconciler()->plan(Program::findOrFail($this->program->id));
        $second = $this->reconcile('program_updated');

        $this->assertSame(2, $first->created);
        $this->assertTrue($plan->isEmpty());
        $this->assertFalse($second->hasChanges());
        $this->assertSame(2, $second->kept);
        $this->assertSame($guids, $this->activeAlerts()->pluck('guid')->sort()->values()->all());
        $this->assertSame(1, ProgramAlertReconciliation::where('program_id', $this->program->id)->count()); // empty runs leave no audit row
    }

    public function test_created_alerts_carry_logical_key_and_payload_v2(): void
    {
        $target = $this->addTarget('2030-06-10');
        $this->reconcile('program_created');

        $alert = $this->activeAlerts()->firstOrFail();
        $this->assertNotNull($alert->logical_key);
        $this->assertSame(2, $alert->payload['contextVersion']);
        $this->assertSame($target->guid, $alert->payload['programTargetGuid']);
        $this->assertSame($this->protocolAlert->guid, $alert->payload['protocolTaskAlertGuid']);
    }

    public function test_changing_one_group_date_only_reschedules_that_group(): void
    {
        $a = $this->addTarget('2030-06-10');
        $this->addTarget('2030-09-10');
        $this->reconcile('program_created');
        $untouched = $this->activeAlerts()->where('payload->programTargetGuid', '!=', $a->guid)->firstOrFail();
        $old = $this->activeAlerts()->where('payload->programTargetGuid', $a->guid)->firstOrFail();

        $a->update(['target_date' => '2030-07-01']);
        $report = $this->reconcile('program_updated');

        $this->assertSame(['rescheduled' => 1], $report->cancelledByReason);
        $this->assertSame(1, $report->created);
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertNull($old->fresh()->logical_key);
        $this->assertNotNull($old->fresh()->cancelled_at);
        $this->assertSame('pending', $untouched->fresh()->status);
        $this->assertSame($untouched->logical_key, $untouched->fresh()->logical_key);
    }

    public function test_rescheduling_back_reuses_the_key_and_never_recreates_an_already_dispatched_alert(): void
    {
        $target = $this->addTarget('2030-06-10');
        $this->reconcile('program_created');
        $key = $this->activeAlerts()->firstOrFail()->logical_key;

        // Pending alert: moved away and back -> a new row with the same (freed) key.
        $target->update(['target_date' => '2030-07-01']);
        $this->reconcile('program_updated');
        $target->update(['target_date' => '2030-06-10']);
        $this->reconcile('program_updated');
        $this->assertSame(1, $this->activeAlerts()->count());
        $this->assertSame($key, $this->activeAlerts()->firstOrFail()->logical_key);

        // Dispatched alert: moving back to its date must NOT resend it.
        $this->activeAlerts()->firstOrFail()->update(['status' => 'dispatched']);
        $target->update(['target_date' => '2030-07-01']);
        $this->reconcile('program_updated');
        $target->update(['target_date' => '2030-06-10']);
        $report = $this->reconcile('program_updated');

        $this->assertSame(0, $report->created);
        $this->assertSame(1, $report->untouchedDispatched);
        $this->assertSame(1, $this->taskDueAlerts()->where('status', 'dispatched')->count());
        $this->assertSame(0, $this->taskDueAlerts()->where('status', 'pending')->count());
    }

    public function test_changing_the_protocol_cancels_pending_alerts_and_leaves_dispatched_ones(): void
    {
        $this->addTarget('2030-06-10');
        $this->addTarget('2030-09-10');
        $this->reconcile('program_created');
        $dispatched = $this->activeAlerts()->orderBy('scheduled_at')->firstOrFail();
        $dispatched->update(['status' => 'dispatched']);

        $other = Protocol::create([
            'guid' => Str::uuid()->toString(), 'technique_id' => $this->program->technique_id, 'vet_id' => $this->vet->id,
            'created_by_type' => 'vet', 'created_by_id' => 1, 'name' => 'Otro',
        ]);
        $this->program->update(['protocol_id' => $other->id]); // no tasks -> nothing desired
        $report = $this->reconcile('program_updated');

        $this->assertSame(['protocol_changed' => 1], $report->cancelledByReason);
        $this->assertSame('dispatched', $dispatched->fresh()->status);
    }

    public function test_added_manager_gets_pending_alerts_but_not_dispatched_ones(): void
    {
        $this->addTarget('2030-06-10');
        $this->addTarget('2030-09-10');
        $this->reconcile('program_created');
        $dispatched = $this->activeAlerts()->orderBy('scheduled_at')->firstOrFail();
        $dispatched->update(['status' => 'dispatched']);
        $pending = $this->activeAlerts()->where('status', 'pending')->firstOrFail();

        $newcomer = $this->profile($this->program->client, Role::where('name', 'client-manager')->firstOrFail());
        $this->program->managers()->attach($newcomer->id);
        $report = $this->reconcile('program_updated');

        $this->assertSame(1, $report->recipientsAdded);
        $this->assertTrue($pending->recipients()->where('user_profile_id', $newcomer->id)->exists());
        $this->assertFalse($dispatched->recipients()->where('user_profile_id', $newcomer->id)->exists());
    }

    public function test_removed_manager_is_suppressed_then_reactivated_and_removing_everyone_cancels_the_alert(): void
    {
        $this->addTarget('2030-06-10');
        $this->reconcile('program_created');
        $alert = $this->activeAlerts()->firstOrFail();

        $this->program->managers()->detach($this->owner->id);
        $this->assertSame(1, $this->reconcile('program_updated')->recipientsSuppressed);
        $this->assertSame(1, $alert->recipients()->where('status', 'suppressed')->count());

        $this->program->managers()->attach($this->owner->id);
        $report = $this->reconcile('program_updated');
        $this->assertSame(1, $report->recipientsReactivated);
        $row = $alert->recipients()->where('user_profile_id', $this->owner->id)->firstOrFail();
        $this->assertSame('pending', $row->status->value);
        $this->assertNull($row->failure_code);
        $this->assertSame(2, $alert->recipients()->count());

        $this->program->managers()->sync([]);
        $report = $this->reconcile('program_updated');
        $this->assertSame(['recipient_removed' => 1], $report->cancelledByReason);
        $this->assertSame('cancelled', $alert->fresh()->status);
    }

    public function test_legacy_alerts_are_adopted_one_to_one_keeping_their_guid(): void
    {
        $this->addTarget('2030-06-10');
        $this->addTarget('2030-06-10'); // same date: both legacy alerts look identical
        $legacy = [$this->legacyAlert('2030-06-10 08:30:00'), $this->legacyAlert('2030-06-10 08:30:00')];

        $report = $this->reconcile('program_updated');

        $this->assertSame(2, $report->adopted);
        $this->assertSame(0, $report->created);
        $this->assertSame(0, $report->cancelledTotal());
        $this->assertEqualsCanonicalizing(
            array_map(fn (Alert $x) => $x->guid, $legacy),
            $this->activeAlerts()->pluck('guid')->all(),
        );
        foreach ($legacy as $alert) {
            $fresh = $alert->fresh();
            $this->assertNotNull($fresh->logical_key);
            $this->assertSame(2, $fresh->payload['contextVersion']);
        }
        $this->assertSame(2, $this->activeAlerts()->pluck('logical_key')->unique()->count());
        $this->assertTrue($this->reconciler()->plan(Program::findOrFail($this->program->id))->isEmpty());
    }

    public function test_scheduler_down_with_five_overdue_alerts_loses_none_on_edit(): void
    {
        foreach (['2030-06-02', '2030-06-03', '2030-06-04', '2030-06-05', '2030-06-06'] as $date) {
            $this->addTarget($date);
        }
        $this->reconcile('program_created');
        $this->assertSame(5, $this->activeAlerts()->count());

        Carbon::setTestNow('2030-06-20 12:00:00');
        $report = $this->reconcile('program_updated');

        $this->assertSame(5, $this->activeAlerts()->where('status', 'pending')->count());
        $this->assertSame(0, $report->cancelledTotal());
    }

    public function test_alert_claimed_by_the_dispatcher_between_plan_and_apply_is_left_alone(): void
    {
        $this->addTarget('2030-06-10');
        $this->reconcile('program_created');
        $alert = $this->activeAlerts()->firstOrFail();

        $this->program->managers()->detach($this->owner->id);
        $program = Program::findOrFail($this->program->id);
        $plan = $this->reconciler()->plan($program);
        $this->assertFalse($plan->isEmpty());

        Alert::whereKey($alert->id)->update(['status' => 'dispatched']); // dispatcher wins the race
        $report = $this->reconciler()->apply($program, $plan, 'command');

        $this->assertSame('dispatched', $alert->fresh()->status);
        $this->assertSame(1, $report->untouchedDispatched);
        $this->assertSame(0, $report->recipientsSuppressed);
        $this->assertSame(0, $alert->recipients()->where('status', 'suppressed')->count());
    }

    public function test_past_target_without_a_previous_alert_is_skipped_not_created(): void
    {
        $this->addTarget('2030-05-20'); // already in the past at 2030-06-01

        $report = $this->reconcile('program_created');

        $this->assertSame(1, $report->skippedPast);
        $this->assertSame(0, $this->taskDueAlerts()->count());
    }

    public function test_valve_aborts_automatic_triggers_that_would_cancel_most_pending_alerts(): void
    {
        $this->addTarget('2030-06-10');
        $drop1 = $this->addTarget('2030-07-10');
        $drop2 = $this->addTarget('2030-08-10');
        $this->reconcile('program_created');
        $drop1->delete();
        $drop2->delete();

        $report = $this->reconcile('protocol_updated');

        $this->assertTrue($report->aborted);
        $this->assertSame(3, $this->activeAlerts()->where('status', 'pending')->count());
        $this->assertTrue(ProgramAlertReconciliation::where('program_id', $this->program->id)->where('aborted', true)->exists());

        // A user-driven trigger is not subject to the valve.
        $manual = $this->reconcile('manual_rebuild');
        $this->assertFalse($manual->aborted);
        $this->assertSame(2, $manual->cancelledTotal());
    }

    public function test_a_failing_audit_write_does_not_undo_the_reconciliation(): void
    {
        $this->addTarget('2030-06-10');
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'program_alert_reconciliations') && str_starts_with(strtolower($query->sql), 'insert')) {
                throw new \RuntimeException('audit down');
            }
        });

        $report = $this->reconcile('program_created');

        $this->assertSame(1, $report->created);
        $this->assertSame(1, $this->taskDueAlerts()->count());
        $this->assertSame(0, ProgramAlertReconciliation::count());
    }

    public function test_kill_switch_only_disables_automatic_triggers(): void
    {
        $this->addTarget('2030-06-10');
        config(['notifications.reconciler.enabled' => false]);

        $this->assertTrue($this->reconcile('nightly')->aborted);
        $this->assertSame(0, $this->taskDueAlerts()->count());

        $this->assertSame(1, $this->reconcile('program_created')->created);
    }

    public function test_reconciling_one_program_never_touches_alerts_of_another_vet(): void
    {
        $this->addTarget('2030-06-10');
        $this->reconcile('program_created');

        $otherVet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Otro Vet', 'slug' => 'vet-' . Str::random(8),
            'country_id' => $this->vet->country_id, 'document_type_id' => $this->vet->document_type_id,
            'tax_id' => '20-33333333-3', 'validated_at' => now(),
        ]);
        $other = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $otherVet->id, 'client_id' => $this->program->client_id,
            'establishment_id' => $this->program->establishment_id, 'technique_id' => $this->program->technique_id,
            'protocol_id' => $this->program->protocol_id,
        ]);
        ProgramTarget::create(['program_id' => $other->id, 'target_date' => '2030-06-10']);
        $other->managers()->sync([$this->owner->id]);
        $this->reconciler()->reconcile($other, 'program_created');
        $mine = $this->activeAlerts()->pluck('id')->all();

        $other->targets()->delete();
        $this->reconciler()->reconcile($other, 'manual_rebuild');

        $this->assertSame($mine, $this->activeAlerts()->pluck('id')->all());
        $this->assertSame('pending', $this->activeAlerts()->firstOrFail()->status);
        $this->assertSame(1, Alert::where('subject_id', $other->id)->where('status', 'cancelled')->count());
    }

    public function test_cancelled_program_cancels_pending_alerts_with_program_cancelled(): void
    {
        $this->addTarget('2030-06-10');
        $this->reconcile('program_created');

        $this->program->update(['cancelled_at' => now()]);
        $report = $this->reconcile('program_cancelled');

        $this->assertSame(['program_cancelled' => 1], $report->cancelledByReason);
    }

    public function test_stale_recipient_finder_ignores_suppressed_rows_and_cancelled_alerts(): void
    {
        $this->addTarget('2030-06-10');
        $this->reconcile('program_created');
        $this->program->managers()->detach($this->owner->id);
        $this->assertNotEmpty(app(ProgramRepositoryInterface::class)->findActiveProgramsWithStaleAlertRecipients());

        $this->reconcile('program_updated');

        $this->assertSame([], app(ProgramRepositoryInterface::class)->findActiveProgramsWithStaleAlertRecipients());
    }

    private function reconciler(): ProgramAlertReconciler
    {
        return app(ProgramAlertReconciler::class);
    }

    private function reconcile(string $trigger): ReconcileReport
    {
        return $this->reconciler()->reconcile(Program::findOrFail($this->program->id), $trigger);
    }

    private function activeAlerts()
    {
        return $this->taskDueAlerts()->whereIn('status', ['pending', 'dispatched']);
    }

    private function legacyAlert(string $scheduledAtUtc): Alert
    {
        $alert = new Alert([
            'type' => AlertType::ProgramTaskDue,
            'payload' => ['protocolTaskAlertGuid' => $this->protocolAlert->guid, 'message' => 'Recordatorio'],
            'scheduled_at' => Carbon::parse($scheduledAtUtc, 'UTC'),
            'status' => 'pending',
            'require_confirmation' => false,
            'vet_id' => $this->vet->id,
        ]);
        $alert->subject()->associate($this->program);
        $alert->save();
        app(AlertRecipientFactory::class)->createForManagers($alert, [$this->owner, $this->manager]);

        return $alert;
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
