<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramAlertReconciliation;
use App\Models\UserProfile;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Models\OptOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProgramAlertFixture;
use Tests\TestCase;

/** Manual resend (RF-15) and "Recalcular avisos" (RF-17) of the program Avisos tab. */
class ProgramAlertRetryRebuildTest extends TestCase
{
    use BuildsProgramAlertFixture;
    use RefreshDatabase;

    private UserProfile $owner;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-06-01 10:00:00');
        Queue::fake();
        $this->buildProgramFixture();
        $this->owner = $this->makeResponsible(name: 'María Pérez', whatsapp: '5491122334455', email: 'maria@example.com');
        $this->program = $this->makeProgram(['2030-06-10'], [$this->owner]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function base(?Program $program = null): string
    {
        return "/api/v1/vets/{$this->vet->guid}/programs/" . ($program ?? $this->program)->guid . '/alerts';
    }

    private function retryUrl(AlertRecipient|string $recipient, ?Program $program = null): string
    {
        $guid = $recipient instanceof AlertRecipient ? $recipient->guid : $recipient;

        return $this->base($program) . "/{$guid}/retry";
    }

    /** @param array<string, mixed> $attrs */
    private function makeAttempt(
        Channel $channel = Channel::Whatsapp,
        DeliveryStatus $status = DeliveryStatus::Failed,
        ?FailureCode $code = FailureCode::NoWhatsappContact,
        string $alertStatus = 'dispatched',
        ?Alert $alert = null,
        array $attrs = [],
    ): AlertRecipient {
        if ($alert === null) {
            $alert = new Alert([
                'type' => AlertType::ProgramTaskDue,
                'payload' => ['message' => 'Traer termo', 'protocolTaskAlertGuid' => $this->protocolAlert->guid],
                'scheduled_at' => '2030-05-30 11:30:00',
                'status' => $alertStatus,
                'require_confirmation' => false,
                'vet_id' => $this->program->vet_id,
            ]);
            $alert->guid = Str::uuid()->toString();
            $alert->subject()->associate($this->program);
            $alert->save();
        }

        return AlertRecipient::create(array_merge([
            'guid' => Str::uuid()->toString(),
            'alert_id' => $alert->id,
            'user_profile_id' => $this->owner->id,
            'channel' => $channel,
            'status' => $status,
            'failure_code' => $code,
            'failure_reason' => 'internal detail',
            'provider_message_id' => 'wamid.OLD',
            'idempotency_key' => 'old-' . Str::uuid(),
        ], $attrs));
    }

    private function send(string $url, $user = null)
    {
        return $this->actingAs($user ?? $this->vetUser, 'sanctum')->postJson($url);
    }

    public function test_retry_puts_the_same_row_back_to_pending_and_queues_exactly_one_job(): void
    {
        $attempt = $this->makeAttempt();

        $this->send($this->retryUrl($attempt))
            ->assertOk()
            ->assertJsonPath('data.recipient_guid', $attempt->guid)
            ->assertJsonPath('data.state', 'pending')
            ->assertJsonPath('data.retry_count', 1);

        $fresh = $attempt->fresh();
        $this->assertSame(DeliveryStatus::Pending, $fresh->status);
        $this->assertNull($fresh->failure_code);
        $this->assertNull($fresh->failure_reason);
        $this->assertNull($fresh->provider_message_id);
        $this->assertStringNotContainsString('old-', $fresh->idempotency_key);
        $this->assertSame(1, $fresh->retry_count);
        $this->assertSame($this->vetUser->id, $fresh->last_retry_by_user_id);
        $this->assertNotNull($fresh->last_retry_at);
        $this->assertSame(1, AlertRecipient::count());

        Queue::assertPushed(DeliverAlertJob::class, 1);
        Queue::assertPushed(DeliverAlertJob::class, fn (DeliverAlertJob $job) => $job->recipientId === $attempt->id);
    }

    public function test_double_click_is_refused_as_recently_retried(): void
    {
        $attempt = $this->makeAttempt(code: FailureCode::ProviderUnreachable);

        $this->send($this->retryUrl($attempt))->assertOk();

        // The job failed again quickly (simulated): the row is back to failed, but the cooldown still applies.
        $attempt->fresh()->update(['status' => DeliveryStatus::Failed, 'failure_code' => FailureCode::ProviderUnreachable]);

        $this->send($this->retryUrl($attempt))
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'recently_retried');

        Queue::assertPushed(DeliverAlertJob::class, 1);

        Carbon::setTestNow('2030-06-01 10:11:00');
        $this->send($this->retryUrl($attempt))->assertOk()->assertJsonPath('data.retry_count', 2);
    }

    public function test_suppressed_by_rate_limit_is_retryable_but_other_suppressions_are_not(): void
    {
        $limited = $this->makeAttempt(status: DeliveryStatus::Suppressed, code: FailureCode::RateLimited);
        $this->send($this->retryUrl($limited))->assertOk();

        foreach ([FailureCode::NoLongerApplicable, FailureCode::Duplicate, FailureCode::OptedOut, null] as $code) {
            $row = $this->makeAttempt(status: DeliveryStatus::Suppressed, code: $code);
            $this->send($this->retryUrl($row))->assertStatus(422)->assertJsonPath('errors.reason', 'not_retryable');
        }
    }

    public function test_rows_that_are_not_failed_or_suppressed_are_not_retryable(): void
    {
        foreach ([DeliveryStatus::Sent, DeliveryStatus::Pending, DeliveryStatus::Delivered] as $status) {
            $attempt = $this->makeAttempt(status: $status, code: null);
            $this->send($this->retryUrl($attempt))->assertStatus(422)->assertJsonPath('errors.reason', 'not_retryable');
        }

        Queue::assertNothingPushed();
    }

    public function test_a_failure_code_that_cannot_be_fixed_is_not_retryable(): void
    {
        $attempt = $this->makeAttempt(code: FailureCode::Duplicate);

        $this->send($this->retryUrl($attempt))->assertStatus(422)->assertJsonPath('errors.reason', 'not_retryable');
    }

    public function test_a_still_scheduled_alert_is_not_retryable(): void
    {
        $attempt = $this->makeAttempt(alertStatus: 'pending', code: FailureCode::ProviderUnreachable);

        $this->send($this->retryUrl($attempt))->assertStatus(422)->assertJsonPath('errors.reason', 'not_retryable');
    }

    public function test_cancelled_alert_and_cancelled_program_are_refused(): void
    {
        $cancelledAlert = $this->makeAttempt(alertStatus: 'cancelled', code: FailureCode::ProviderUnreachable);
        $this->send($this->retryUrl($cancelledAlert))->assertStatus(422)->assertJsonPath('errors.reason', 'alert_cancelled');

        $attempt = $this->makeAttempt(code: FailureCode::ProviderUnreachable);
        $this->program->update(['cancelled_at' => now()]);

        $this->send($this->retryUrl($attempt))->assertStatus(422)->assertJsonPath('errors.reason', 'program_cancelled');
        Queue::assertNothingPushed();
    }

    public function test_opt_out_is_checked_live_even_when_the_code_was_retryable(): void
    {
        $attempt = $this->makeAttempt(code: FailureCode::ProviderUnreachable);
        OptOut::create(['phone' => '5491122334455', 'channel' => Channel::Whatsapp]);

        $this->send($this->retryUrl($attempt))->assertStatus(422)->assertJsonPath('errors.reason', 'opted_out');

        $this->assertSame(DeliveryStatus::Failed, $attempt->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_sibling_that_already_reached_the_person_blocks_the_resend(): void
    {
        $whatsapp = $this->makeAttempt(code: FailureCode::ProviderUnreachable);
        $this->makeAttempt(Channel::Email, DeliveryStatus::Sent, null, alert: $whatsapp->alert);

        $this->send($this->retryUrl($whatsapp))->assertStatus(422)->assertJsonPath('errors.reason', 'already_delivered');
        Queue::assertNothingPushed();
    }

    public function test_contact_failures_need_the_contact_to_be_fixed_first(): void
    {
        $noContact = $this->makeResponsible(whatsapp: null, name: 'Sin Contacto');
        $this->program->managers()->attach($noContact->id);

        $attempt = $this->makeAttempt(code: FailureCode::NoWhatsappContact, attrs: ['user_profile_id' => $noContact->id]);

        $this->send($this->retryUrl($attempt))->assertStatus(422)->assertJsonPath('errors.reason', 'contact_still_missing');

        $noContact->contacts()->create(['type' => \App\Enums\ContactType::Whatsapp, 'value' => '5491199887766', 'use_for_alerts' => true, 'is_primary' => true]);

        $this->send($this->retryUrl($attempt))->assertOk();
        Queue::assertPushed(DeliverAlertJob::class, 1);
    }

    public function test_an_invalid_number_stays_blocked_until_corrected(): void
    {
        $attempt = $this->makeAttempt(code: FailureCode::InvalidNumber);
        $this->owner->contacts()->where('type', 'whatsapp')->update(['value' => '123']);

        $this->send($this->retryUrl($attempt))->assertStatus(422)->assertJsonPath('errors.reason', 'contact_still_missing');
    }

    public function test_a_person_who_is_no_longer_responsible_is_not_retryable(): void
    {
        $attempt = $this->makeAttempt(code: FailureCode::ProviderUnreachable);
        $this->program->managers()->detach($this->owner->id);

        $this->send($this->retryUrl($attempt))->assertStatus(422)->assertJsonPath('errors.reason', 'not_retryable');
    }

    public function test_other_tenant_unknown_guid_and_foreign_program_are_404(): void
    {
        $attempt = $this->makeAttempt(code: FailureCode::ProviderUnreachable);

        $this->send($this->retryUrl(Str::uuid()->toString()))->assertNotFound();

        // Same recipient guid through another program of the same vet.
        $other = $this->makeProgram(['2030-07-01'], [$this->owner]);
        $this->send($this->retryUrl($attempt, $other))->assertNotFound();

        // Program of another vet.
        $foreignVet = $this->makeVet('Otro Vet');
        $foreign = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $foreignVet->id, 'client_id' => $this->client->id,
            'establishment_id' => $this->establishment->id, 'technique_id' => $this->technique->id, 'protocol_id' => $this->protocol->id,
        ]);
        $this->send($this->retryUrl($attempt, $foreign))->assertNotFound();

        // An alert of the foreign vet that happens to point at this program id is not reachable either.
        $stray = new Alert([
            'type' => AlertType::ProgramTaskDue, 'payload' => [], 'scheduled_at' => '2030-05-30 11:30:00',
            'status' => 'dispatched', 'require_confirmation' => false, 'vet_id' => $foreignVet->id,
        ]);
        $stray->guid = Str::uuid()->toString();
        $stray->subject()->associate($this->program);
        $stray->save();
        $strayAttempt = $this->makeAttempt(code: FailureCode::ProviderUnreachable, alert: $stray);
        $this->send($this->retryUrl($strayAttempt))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_retry_and_rebuild_require_programs_update(): void
    {
        $attempt = $this->makeAttempt(code: FailureCode::ProviderUnreachable);
        $assistant = $this->makeStaffUser($this->vet, 'vet-assistant');

        $this->send($this->retryUrl($attempt), $assistant)->assertForbidden();
        $this->send($this->base() . '/rebuild', $assistant)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_rebuild_repairs_a_missing_alert_and_is_idempotent(): void
    {
        // Nothing persisted yet: the program is out of sync with its plan.
        $this->assertSame(0, Alert::count());

        $this->send($this->base() . '/rebuild')
            ->assertOk()
            ->assertJsonPath('data.reconciliation.created', 1)
            ->assertJsonStructure(['data' => ['reconciliation', 'warnings']]);

        $this->assertSame(1, Alert::where('type', AlertType::ProgramTaskDue)->count());
        $audit = ProgramAlertReconciliation::where('program_id', $this->program->id)->first();
        $this->assertSame('manual_rebuild', $audit->trigger);
        $this->assertSame($this->vetUser->id, $audit->user_id);

        // A hand-deleted alert is recreated.
        Alert::query()->each(function (Alert $alert) {
            $alert->recipients()->delete();
            $alert->delete();
        });
        $this->send($this->base() . '/rebuild')->assertOk()->assertJsonPath('data.reconciliation.created', 1);

        // Second call: nothing to change, nothing new audited.
        $audits = ProgramAlertReconciliation::count();
        $alerts = Alert::count();
        $this->send($this->base() . '/rebuild')
            ->assertOk()
            ->assertJsonPath('data.reconciliation.created', 0)
            ->assertJsonPath('data.reconciliation.cancelled', 0)
            ->assertJsonPath('data.reconciliation.rescheduled', 0);
        $this->assertSame($audits, ProgramAlertReconciliation::count());
        $this->assertSame($alerts, Alert::count());
    }

    public function test_rebuild_is_not_editable_for_a_cancelled_program_and_404_for_foreign_ones(): void
    {
        $this->program->update(['cancelled_at' => now()]);

        $this->send($this->base() . '/rebuild')->assertStatus(422)->assertJsonPath('errors.reason', 'not_editable');

        $foreignVet = $this->makeVet('Otro Vet');
        $foreign = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $foreignVet->id, 'client_id' => $this->client->id,
            'establishment_id' => $this->establishment->id, 'technique_id' => $this->technique->id, 'protocol_id' => $this->protocol->id,
        ]);
        $this->send($this->base($foreign) . '/rebuild')->assertNotFound();
    }

    public function test_the_tab_hint_agrees_with_the_resend_rules(): void
    {
        $this->makeAttempt(status: DeliveryStatus::Suppressed, code: FailureCode::NoLongerApplicable, attrs: ['user_profile_id' => $this->makeResponsible(name: 'Otra')->id]);
        $attempt = $this->makeAttempt(code: FailureCode::ProviderUnreachable);

        $lines = $this->actingAs($this->vetUser, 'sanctum')->getJson($this->base())->assertOk()->json('data.data');
        $hint = collect($lines)->firstWhere('recipient.profile_guid', $this->owner->guid)['retry'];

        $this->assertTrue($hint['allowed']);
        $this->assertSame($attempt->guid, $hint['recipient_guid']);
    }
}
