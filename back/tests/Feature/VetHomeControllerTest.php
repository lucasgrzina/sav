<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\ProgramTarget;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Services\VetHomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProgramAlertFixture;
use Tests\TestCase;

class VetHomeControllerTest extends TestCase
{
    use BuildsProgramAlertFixture;
    use RefreshDatabase;

    private UserProfile $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-06-01 10:00:00'); // 07:00 in Buenos Aires
        $this->buildProgramFixture();
        $this->owner = $this->makeResponsible(name: 'María Pérez', whatsapp: '5491122334455', email: 'maria@example.com');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(?string $vetGuid = null): string
    {
        return '/api/v1/vets/' . ($vetGuid ?? $this->vet->guid) . '/home';
    }

    private function fetch(?User $user = null)
    {
        return $this->actingAs($user ?? $this->vetUser, 'sanctum')->getJson($this->url());
    }

    /**
     * @param list<array{0: UserProfile, 1: Channel, 2: DeliveryStatus, 3?: array<string, mixed>}> $attempts
     * @param array<string, mixed> $payload
     */
    private function makeAlert(Program $program, string $scheduledAt, array $attempts, string $status = 'dispatched', array $payload = []): Alert
    {
        $alert = new Alert([
            'type' => AlertType::ProgramTaskDue,
            'payload' => $payload + ['message' => 'Traer termo', 'taskDescription' => 'Colecta de embriones'],
            'scheduled_at' => $scheduledAt,
            'status' => $status,
            'require_confirmation' => false,
            'vet_id' => $program->vet_id,
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

    private function sent(string $sentAt, array $extra = []): array
    {
        return [$this->owner, Channel::Whatsapp, DeliveryStatus::Sent, ['sent_at' => $sentAt, 'destination_hint' => '+54 ••• •••• 4455'] + $extra];
    }

    public function test_empty_vet_returns_the_full_shape_with_empty_blocks(): void
    {
        $this->fetch()->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.timezone', 'America/Argentina/Buenos_Aires')
            ->assertJsonPath('data.upcoming_programs', [])
            ->assertJsonPath('data.recent_alerts', [])
            ->assertJsonPath('data.problem_alerts', [])
            ->assertJsonStructure(['data' => ['generated_at', 'timezone', 'upcoming_programs', 'recent_alerts', 'problem_alerts']]);
    }

    public function test_upcoming_programs_are_the_next_five_by_nearest_target_date_and_skip_past_and_cancelled(): void
    {
        $dates = ['2030-06-20', '2030-06-05', '2030-06-12', '2030-06-02', '2030-06-30', '2030-06-08', '2030-07-15'];
        foreach ($dates as $date) {
            $this->makeProgram([$date], [$this->owner]);
        }
        $this->makeProgram(['2030-05-20'], [$this->owner]); // already past
        $cancelled = $this->makeProgram(['2030-06-03'], [$this->owner]);
        $cancelled->update(['cancelled_at' => now()]);
        // Multi-group program: its nearest FUTURE target counts, not the past one.
        $multi = $this->makeProgram(['2030-05-01', '2030-06-04'], [$this->owner]);

        $response = $this->fetch()->assertOk();

        $this->assertSame(
            ['2030-06-02', '2030-06-04', '2030-06-05', '2030-06-08', '2030-06-12'],
            array_column($response->json('data.upcoming_programs'), 'next_target_date'),
        );
        $first = $response->json('data.upcoming_programs.1');
        $this->assertSame($multi->guid, $first['guid']);
        $this->assertSame('Cliente Test', $first['client']['name']);
        $this->assertSame('Estancia Test', $first['establishment']['name']);
        $this->assertSame('Protocolo Test', $first['protocol']['name']);
        $this->assertSame('IATF', $first['technique']['name']);
        $this->assertArrayNotHasKey('id', $first);
    }

    public function test_today_is_resolved_in_the_vet_timezone(): void
    {
        // 2030-06-02 01:00 UTC is still 2030-06-01 22:00 in Buenos Aires: a program of the 1st is still upcoming.
        Carbon::setTestNow('2030-06-02 01:00:00');
        $this->makeProgram(['2030-06-01'], [$this->owner]);

        $this->fetch()->assertOk()->assertJsonPath('data.upcoming_programs.0.next_target_date', '2030-06-01');
    }

    public function test_recent_alerts_list_sent_lines_newest_first_with_masked_destination_and_program(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->owner]);
        $this->makeAlert($program, '2030-05-30 11:30:00', [$this->sent('2030-05-30 11:30:05', ['rendered_body' => 'Hola'])]);
        $this->makeAlert($program, '2030-05-31 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Delivered, [
            'sent_at' => '2030-05-31 11:30:05', 'delivered_at' => '2030-05-31 11:30:20', 'destination_hint' => '+54 ••• •••• 4455',
        ]]]);

        $response = $this->fetch()->assertOk()
            ->assertJsonCount(2, 'data.recent_alerts')
            ->assertJsonPath('data.recent_alerts.0.state', 'delivered')
            ->assertJsonPath('data.recent_alerts.1.state', 'sent')
            ->assertJsonPath('data.recent_alerts.0.recipient.name', 'María Pérez')
            ->assertJsonPath('data.recent_alerts.0.task_description', 'Colecta de embriones')
            ->assertJsonPath('data.recent_alerts.0.destination_masked', '+54 ••• •••• 4455')
            ->assertJsonPath('data.recent_alerts.0.program.guid', $program->guid)
            ->assertJsonPath('data.recent_alerts.0.sent_at_local.time', '08:30')
            ->assertJsonPath('data.problem_alerts', []);

        $json = $response->getContent();
        foreach (['5491122334455', 'maria@example.com', 'failure_reason', 'provider_message_id', 'idempotency_key', 'rendered_body'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_recent_alerts_are_capped_at_eight(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->owner]);
        for ($i = 1; $i <= 10; $i++) {
            $day = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->makeAlert($program, "2030-05-{$day} 11:30:00", [$this->sent("2030-05-{$day} 11:30:05")]);
        }

        $response = $this->fetch()->assertOk()->assertJsonCount(VetHomeService::RECENT_ALERTS_LIMIT, 'data.recent_alerts');
        $this->assertStringStartsWith('2030-05-10', $response->json('data.recent_alerts.0.sent_at_local.date'));
    }

    public function test_problem_alerts_show_failure_code_program_and_exclude_cancelled_and_recovered_lines(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->owner]);
        $other = $this->makeResponsible(name: 'Pedro Gómez', whatsapp: null);

        $failed = $this->makeAlert($program, '2030-05-31 11:30:00', [
            [$this->owner, Channel::Whatsapp, DeliveryStatus::Failed, ['failure_code' => FailureCode::ProviderRejected, 'failure_reason' => 'SECRET detail']],
        ]);
        $notSent = $this->makeAlert($program, '2030-05-30 11:30:00', [
            [$other, Channel::Whatsapp, DeliveryStatus::Suppressed, ['failure_code' => FailureCode::NoWhatsappContact]],
        ]);
        // Failed on WhatsApp but reached through the e-mail fallback: not a problem.
        $this->makeAlert($program, '2030-05-29 11:30:00', [
            [$this->owner, Channel::Whatsapp, DeliveryStatus::Failed, ['failure_code' => FailureCode::ProviderRejected]],
            [$this->owner, Channel::Email, DeliveryStatus::Sent, ['sent_at' => '2030-05-29 11:31:00']],
        ]);
        // Cancelled alert and person no longer responsible: not delivery problems.
        $this->makeAlert($program, '2030-05-28 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Failed]], status: 'cancelled');
        $this->makeAlert($program, '2030-05-27 11:30:00', [
            [$other, Channel::Whatsapp, DeliveryStatus::Suppressed, ['failure_code' => FailureCode::NoLongerApplicable]],
        ]);
        // Older than the attention window.
        $this->makeAlert($program, '2030-03-01 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Failed]]);
        // Cancelled program.
        $cancelledProgram = $this->makeProgram(['2030-06-11'], [$this->owner]);
        $cancelledProgram->update(['cancelled_at' => now()]);
        $this->makeAlert($cancelledProgram, '2030-05-31 12:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Failed]]);

        $response = $this->fetch()->assertOk()
            ->assertJsonCount(2, 'data.problem_alerts')
            ->assertJsonPath('data.problem_alerts.0.key', $failed->guid . ':' . $this->owner->guid)
            ->assertJsonPath('data.problem_alerts.0.state', 'failed')
            ->assertJsonPath('data.problem_alerts.0.failure_code', 'provider_rejected')
            ->assertJsonPath('data.problem_alerts.0.program.guid', $program->guid)
            ->assertJsonPath('data.problem_alerts.0.program.client.name', 'Cliente Test')
            ->assertJsonPath('data.problem_alerts.1.key', $notSent->guid . ':' . $other->guid)
            ->assertJsonPath('data.problem_alerts.1.state', 'not_sent')
            ->assertJsonPath('data.problem_alerts.1.failure_code', 'no_whatsapp_contact');

        $this->assertStringNotContainsString('SECRET', $response->getContent());
    }

    public function test_other_vet_data_is_never_exposed(): void
    {
        $otherVet = $this->makeVet('Otra Vet');
        $otherProgram = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $otherVet->id, 'client_id' => $this->client->id,
            'establishment_id' => $this->establishment->id, 'technique_id' => $this->technique->id, 'protocol_id' => $this->protocol->id,
        ]);
        ProgramTarget::create(['program_id' => $otherProgram->id, 'target_date' => '2030-06-05']);
        $this->makeAlert($otherProgram, '2030-05-31 11:30:00', [$this->sent('2030-05-31 11:30:05')]);
        $this->makeAlert($otherProgram, '2030-05-30 11:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Failed]]);

        $this->fetch()->assertOk()
            ->assertJsonPath('data.upcoming_programs', [])
            ->assertJsonPath('data.recent_alerts', [])
            ->assertJsonPath('data.problem_alerts', []);

        // A user of this vet cannot read another vet's home.
        $this->actingAs($this->vetUser, 'sanctum')->getJson($this->url($otherVet->guid))->assertForbidden();
    }

    public function test_requires_programs_read_and_hides_alert_blocks_without_programs_alerts_read(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->owner]);
        $this->makeAlert($program, '2030-05-31 11:30:00', [$this->sent('2030-05-31 11:30:05')]);

        // No programs.read at all.
        $this->fetch($this->makeStaffUser($this->vet, 'vet-assistant'))->assertForbidden();

        // programs.read only: programs yes, delivery data no (null, not empty).
        $this->role('vet-administrative')->givePermissionTo(Permission::where('name', 'programs.read')->first());
        $this->fetch($this->makeStaffUser($this->vet, 'vet-administrative'))->assertOk()
            ->assertJsonCount(1, 'data.upcoming_programs')
            ->assertJsonPath('data.recent_alerts', null)
            ->assertJsonPath('data.problem_alerts', null);

        $this->app['auth']->forgetGuards();
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_client_side_profile_is_restricted_to_its_establishments(): void
    {
        $mine = $this->makeProgram(['2030-06-10'], [$this->owner]);
        $otherEstablishment = Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $this->client->id, 'name' => 'Otra Estancia']);
        $theirs = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $this->vet->id, 'client_id' => $this->client->id,
            'establishment_id' => $otherEstablishment->id, 'technique_id' => $this->technique->id, 'protocol_id' => $this->protocol->id,
        ]);
        ProgramTarget::create(['program_id' => $theirs->id, 'target_date' => '2030-06-03']);
        $this->makeAlert($mine, '2030-05-31 11:30:00', [$this->sent('2030-05-31 11:30:05')]);
        $this->makeAlert($theirs, '2030-05-31 12:30:00', [$this->sent('2030-05-31 12:30:05')]);
        $this->makeAlert($theirs, '2030-05-30 12:30:00', [[$this->owner, Channel::Whatsapp, DeliveryStatus::Failed]]);

        $service = app(VetHomeService::class);

        // Vet staff see the whole tenant.
        $all = $service->forProfile($this->vet, $this->vetUser->profiles()->first(), true);
        $this->assertCount(2, $all['upcoming_programs']);
        $this->assertCount(2, $all['recent_alerts']);

        // The client-side profile is linked to "Estancia Test" only.
        $scoped = $service->forProfile($this->vet, $this->owner, true);
        $this->assertSame([$mine->guid], array_column($scoped['upcoming_programs'], 'guid'));
        $this->assertSame([$mine->guid], array_map(fn (array $a) => $a['program']['guid'], $scoped['recent_alerts']));
        $this->assertSame([], $scoped['problem_alerts']);

        // A client-side profile with no establishments sees nothing.
        $lonely = $this->makeResponsible(name: 'Sin Campo', client: $this->makeClient('Otro Cliente'));
        $empty = $service->forProfile($this->vet, $lonely, true);
        $this->assertSame([], $empty['upcoming_programs']);
        $this->assertSame([], $empty['recent_alerts']);
        $this->assertSame([], $empty['problem_alerts']);
    }
}
