<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramTarget;
use App\Models\UserProfile;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProgramAlertFixture;
use Tests\TestCase;

class ProgramAlertControllerTest extends TestCase
{
    use BuildsProgramAlertFixture;
    use RefreshDatabase;

    private UserProfile $owner;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-06-01 10:00:00');
        $this->buildProgramFixture();
        $this->owner = $this->makeResponsible(name: 'María Pérez', whatsapp: '5491122334455', email: 'maria@example.com');
        $this->program = $this->makeProgram(['2030-06-10'], [$this->owner]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(?Program $program = null, string $query = ''): string
    {
        return "/api/v1/vets/{$this->vet->guid}/programs/" . ($program ?? $this->program)->guid . '/alerts' . $query;
    }

    private function fetch(string $query = '', ?Program $program = null)
    {
        return $this->actingAs($this->vetUser, 'sanctum')->getJson($this->url($program, $query));
    }

    /**
     * @param list<array{0: UserProfile, 1: Channel, 2: DeliveryStatus, 3?: array<string, mixed>}> $attempts
     * @param array<string, mixed> $payload
     */
    private function makeAlert(
        string $scheduledAt,
        array $attempts,
        string $status = 'dispatched',
        array $payload = [],
        AlertType $type = AlertType::ProgramTaskDue,
        ?Program $program = null,
        ?int $vetId = null,
    ): Alert {
        $program ??= $this->program;
        $alert = new Alert([
            'type' => $type,
            'payload' => $payload + ['message' => 'Traer termo', 'protocolTaskAlertGuid' => $this->protocolAlert->guid],
            'scheduled_at' => $scheduledAt,
            'status' => $status,
            'require_confirmation' => false,
            'vet_id' => $vetId ?? $program->vet_id,
        ]);
        $alert->guid = Str::uuid()->toString();
        $alert->subject()->associate($program);
        $alert->save();

        foreach ($attempts as $attempt) {
            AlertRecipient::create([
                'guid' => Str::uuid()->toString(),
                'alert_id' => $alert->id,
                'user_profile_id' => $attempt[0]->id,
                'channel' => $attempt[1],
                'status' => $attempt[2],
                'idempotency_key' => Str::uuid()->toString(),
            ] + ($attempt[3] ?? []));
        }

        return $alert;
    }

    public function test_lists_a_sent_line_with_the_full_contract(): void
    {
        $target = $this->program->targets->first();
        $this->makeAlert('2030-05-30 11:30:00', [
            [$this->owner, Channel::Whatsapp, DeliveryStatus::Sent, [
                'sent_at' => '2030-05-30 11:30:05', 'destination_hint' => '+54 ••• •••• 4455',
                'provider_message_id' => 'wamid.SECRET', 'failure_reason' => 'internal detail', 'confirmed_at' => '2030-05-30 12:00:00',
                'rendered_body' => 'Hola María',
            ]],
        ], payload: [
            'programTargetGuid' => $target->guid, 'protocolTaskGuid' => $this->task->guid, 'targetDate' => '2030-06-10',
            'taskDescription' => 'Colecta de embriones', 'taskDate' => '2030-06-10', 'taskTime' => '08:00',
        ]);

        $response = $this->fetch()->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.per_page', 25)
            ->assertJsonPath('data.data.0.type', 'program.task_due')
            ->assertJsonPath('data.data.0.state', 'sent')
            ->assertJsonPath('data.data.0.bucket', 'sent')
            ->assertJsonPath('data.data.0.channel', 'whatsapp')
            ->assertJsonPath('data.data.0.recipient.name', 'María Pérez')
            ->assertJsonPath('data.data.0.recipient.role', 'client-owner')
            ->assertJsonPath('data.data.0.recipient.profile_guid', $this->owner->guid)
            ->assertJsonPath('data.data.0.destination_masked', '+54 ••• •••• 4455')
            ->assertJsonPath('data.data.0.scheduled_at_utc', '2030-05-30T11:30:00Z')
            ->assertJsonPath('data.data.0.scheduled_at_local.time', '08:30')
            ->assertJsonPath('data.data.0.task.description', 'Colecta de embriones')
            ->assertJsonPath('data.data.0.task.protocol_task_guid', $this->task->guid)
            ->assertJsonPath('data.data.0.group.target_guid', $target->guid)
            ->assertJsonPath('data.data.0.group.label', 'grupo del 10/06/2030')
            ->assertJsonPath('data.data.0.message', 'Traer termo')
            ->assertJsonPath('data.data.0.rendered_body', 'Hola María')
            ->assertJsonPath('data.data.0.delivered_at', null)
            ->assertJsonPath('data.data.0.fallback.used', false)
            ->assertJsonPath('data.data.0.attempts.0.state', 'sent')
            ->assertJsonPath('data.data.0.retry', null)
            ->assertJsonPath('data.meta.counters.sent', 1)
            ->assertJsonPath('data.meta.counters.total', 1)
            ->assertJsonPath('data.meta.timezone', 'America/Argentina/Buenos_Aires');

        $json = $response->getContent();
        foreach (['failure_reason', 'confirmed_at', 'wamid', 'internal detail', 'provider_message_id', 'idempotency_key', '5491122334455', 'maria@example.com'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertArrayNotHasKey('id', $response->json('data.data.0'));
    }

    public function test_sent_without_delivered_at_is_not_presented_as_delivered_and_real_data_is(): void
    {
        $other = $this->makeResponsible(name: 'Beto');
        $third = $this->makeResponsible(name: 'Carla');
        $this->makeAlert('2030-05-30 11:30:00', [
            [$this->owner, Channel::Whatsapp, DeliveryStatus::Sent],
            [$other, Channel::Whatsapp, DeliveryStatus::Delivered, ['delivered_at' => '2030-05-30 11:31:00']],
            [$third, Channel::Whatsapp, DeliveryStatus::Read, ['delivered_at' => '2030-05-30 11:31:00']],
        ]);

        $lines = collect($this->fetch()->assertOk()->json('data.data'))->keyBy('recipient.name');

        $this->assertSame('sent', $lines['María Pérez']['state']);
        $this->assertNull($lines['María Pérez']['delivered_at']);
        $this->assertSame('delivered', $lines['Beto']['state']);
        $this->assertNotNull($lines['Beto']['delivered_at']);
        $this->assertSame('read', $lines['Carla']['state']);
    }

    public function test_whatsapp_failed_and_email_sent_is_a_single_sent_line_with_fallback(): void
    {
        $this->makeAlert('2030-05-30 11:30:00', [
            [$this->owner, Channel::Whatsapp, DeliveryStatus::Failed, ['failure_code' => FailureCode::InvalidNumber]],
            [$this->owner, Channel::Email, DeliveryStatus::Sent, ['sent_at' => '2030-05-30 11:40:00', 'destination_hint' => 'm•••@example.com']],
        ]);

        $this->fetch()->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.state', 'sent')
            ->assertJsonPath('data.data.0.channel', 'email_fallback')
            ->assertJsonPath('data.data.0.fallback.used', true)
            ->assertJsonPath('data.data.0.fallback.email_state', 'sent')
            ->assertJsonPath('data.data.0.destination_masked', 'm•••@example.com')
            ->assertJsonCount(2, 'data.data.0.attempts')
            ->assertJsonPath('data.data.0.attempts.0.failure_code', 'invalid_number');
    }

    public function test_historic_failed_without_code_is_unknown_and_retry_advice_is_informative(): void
    {
        $this->makeAlert('2030-05-30 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Failed]]);

        $this->fetch()->assertOk()
            ->assertJsonPath('data.data.0.state', 'failed')
            ->assertJsonPath('data.data.0.bucket', 'problem')
            ->assertJsonPath('data.data.0.failure_code', 'unknown')
            ->assertJsonPath('data.data.0.attempts.0.failure_code', 'unknown')
            ->assertJsonPath('data.data.0.contact.status', 'ok')
            ->assertJsonPath('data.data.0.contact.masked', '+54 ••• •••• 4455')
            ->assertJsonPath('data.data.0.retry.allowed', true)
            ->assertJsonPath('data.data.0.retry.blocked_reason', null);
    }

    public function test_missing_contact_blocks_retry_and_opt_out_is_reported(): void
    {
        $noContact = $this->makeResponsible(name: 'Sin Contacto', whatsapp: null);
        $this->makeAlert('2030-05-30 11:30:00', [
            [$noContact, Channel::Whatsapp, DeliveryStatus::Failed, ['failure_code' => FailureCode::NoWhatsappContact]],
            [$this->owner, Channel::Whatsapp, DeliveryStatus::Suppressed, ['failure_code' => FailureCode::OptedOut]],
        ]);

        $lines = collect($this->fetch()->assertOk()->json('data.data'))->keyBy('recipient.name');

        $this->assertSame('contact_still_missing', $lines['Sin Contacto']['retry']['blocked_reason']);
        $this->assertSame('missing', $lines['Sin Contacto']['contact']['status']);
        $this->assertSame('not_sent', $lines['María Pérez']['state']);
        $this->assertSame('opted_out', $lines['María Pérez']['failure_code']);
        $this->assertSame('not_retryable', $lines['María Pérez']['retry']['blocked_reason']);
    }

    public function test_paginates_by_25_and_orders_scheduled_first_then_newest(): void
    {
        $soon = $this->makeAlert('2030-06-02 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Pending]], 'pending');
        $later = $this->makeAlert('2030-06-05 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Pending]], 'pending');
        for ($day = 1; $day <= 27; $day++) {
            $this->makeAlert(sprintf('2030-05-%02d 11:30:00', $day), [[$this->owner, Channel::Whatsapp, DeliveryStatus::Sent]]);
        }

        $first = $this->fetch()->assertOk()
            ->assertJsonPath('data.total', 29)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(25, 'data.data');

        $this->assertSame($soon->guid, $first->json('data.data.0.alert_guid'));
        $this->assertSame($later->guid, $first->json('data.data.1.alert_guid'));
        $this->assertSame('2030-05-27', substr($first->json('data.data.2.scheduled_at_utc'), 0, 10));

        $this->fetch('?page=2')->assertOk()->assertJsonCount(4, 'data.data');
    }

    public function test_filters_run_on_the_server_and_counters_ignore_only_the_status_filter(): void
    {
        $other = $this->makeResponsible(name: 'Beto');
        $groupA = $this->program->targets->first();
        $groupB = ProgramTarget::create(['program_id' => $this->program->id, 'target_date' => '2030-07-01']);

        $this->makeAlert('2030-06-02 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Pending]], 'pending', ['programTargetGuid' => $groupA->guid]);
        $this->makeAlert('2030-05-30 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Sent], [$other, Channel::Whatsapp, DeliveryStatus::Failed]], 'dispatched', ['programTargetGuid' => $groupA->guid]);
        $this->makeAlert('2030-05-29 11:30:00', [[$other, Channel::Whatsapp, DeliveryStatus::Sent]], 'dispatched', ['programTargetGuid' => $groupB->guid]);
        $this->makeAlert('2030-05-28 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Sent]], 'dispatched', [], AlertType::ProgramCreated);

        $all = $this->fetch()->assertOk();
        $this->assertSame(
            ['scheduled' => 1, 'sent' => 3, 'problem' => 1, 'cancelled' => 0, 'total' => 5],
            $all->json('data.meta.counters'),
        );

        $problem = $this->fetch('?status=problem')->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame(5, $problem->json('data.meta.counters.total'), 'status filter does not change the counters');
        $this->assertSame('Beto', $problem->json('data.data.0.recipient.name'));

        $byProfile = $this->fetch('?profile=' . $this->owner->guid)->assertOk()->assertJsonPath('data.total', 3);
        $this->assertSame(3, $byProfile->json('data.meta.counters.total'));

        $byTarget = $this->fetch('?target=' . $groupB->guid)->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame('Beto', $byTarget->json('data.data.0.recipient.name'));

        $byType = $this->fetch('?type=program.created')->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame('program.created', $byType->json('data.data.0.type'));

        $this->fetch('?status=scheduled')->assertOk()->assertJsonPath('data.total', 1);
        $this->fetch('?profile=' . Str::uuid())->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_push_attempts_are_excluded(): void
    {
        $this->makeAlert('2030-05-30 11:30:00', [
            [$this->owner, Channel::Whatsapp, DeliveryStatus::Sent],
            [$this->owner, Channel::Push, DeliveryStatus::Failed, ['failure_code' => FailureCode::Unknown]],
        ]);

        $this->fetch()->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.state', 'sent')
            ->assertJsonCount(1, 'data.data.0.attempts');

        $onlyPush = $this->program->replicate();
        $onlyPush->guid = Str::uuid()->toString();
        $onlyPush->save();
        $this->makeAlert('2030-05-30 11:30:00', [[$this->owner, Channel::Push, DeliveryStatus::Sent]], program: $onlyPush);

        $this->fetch('', $onlyPush)->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_alerts_of_another_vet_with_the_same_subject_id_do_not_appear(): void
    {
        $foreignVet = $this->makeVet('Otro Vet');
        $this->makeAlert('2030-05-30 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Sent]], vetId: $foreignVet->id);
        $this->makeAlert('2030-05-29 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Sent]]);

        $this->fetch()->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_program_of_another_vet_or_unknown_guid_is_404(): void
    {
        $foreignVet = $this->makeVet('Otro Vet');
        $foreign = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $foreignVet->id, 'client_id' => $this->client->id,
            'establishment_id' => $this->establishment->id, 'technique_id' => $this->technique->id, 'protocol_id' => $this->protocol->id,
        ]);

        $this->fetch('', $foreign)->assertNotFound();

        $this->actingAs($this->vetUser, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/programs/" . Str::uuid() . '/alerts')
            ->assertNotFound();
    }

    public function test_requires_the_alerts_read_permission(): void
    {
        $assistant = $this->makeStaffUser($this->vet, 'vet-assistant');

        $this->actingAs($assistant, 'sanctum')->getJson($this->url())->assertForbidden();
    }

    public function test_cancelled_program_shows_cancelled_lines_without_warnings(): void
    {
        $this->makeAlert('2030-06-02 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Pending]], 'cancelled');
        $this->program->update(['cancelled_at' => now()]);
        Alert::query()->update(['cancelled_at' => now(), 'cancel_reason' => 'program_cancelled']);

        $this->fetch()->assertOk()
            ->assertJsonPath('data.data.0.state', 'cancelled')
            ->assertJsonPath('data.data.0.cancel_reason', 'program_cancelled')
            ->assertJsonPath('data.meta.counters.cancelled', 1)
            ->assertJsonPath('data.meta.program_cancelled_at', now()->toISOString())
            ->assertJsonPath('data.meta.warnings', [])
            ->assertJsonPath('data.meta.out_of_sync', false);
    }

    public function test_meta_reports_tab_warnings_and_out_of_sync(): void
    {
        $program = $this->makeProgram(['2030-05-20', '2030-06-10'], [$this->owner]);

        $response = $this->fetch('', $program)->assertOk()
            ->assertJsonPath('data.meta.multi_group', true)
            ->assertJsonPath('data.meta.out_of_sync', true)
            ->assertJsonPath('data.meta.last_reconciled_at', null);

        $codes = collect($response->json('data.meta.warnings'))->keyBy('code');
        $this->assertSame('info', $codes['ALERT_IN_PAST']['severity']);
    }

    public function test_validates_filters(): void
    {
        $this->fetch('?status=bogus')->assertUnprocessable();
        $this->fetch('?type=health_plan.month')->assertUnprocessable();
        $this->fetch('?per_page=51')->assertUnprocessable();
        $this->fetch('?profile=not-a-uuid')->assertUnprocessable();
    }

    public function test_line_without_attempts_does_not_appear_and_removed_person_is_cancelled(): void
    {
        $this->makeAlert('2030-06-02 11:30:00', [
            [$this->owner, Channel::Whatsapp, DeliveryStatus::Suppressed, ['failure_code' => FailureCode::NoLongerApplicable]],
        ], 'pending');
        $this->makeAlert('2030-06-03 11:30:00', []);

        $this->fetch()->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.state', 'cancelled')
            ->assertJsonPath('data.data.0.cancel_reason', 'recipient_removed');
    }
}
