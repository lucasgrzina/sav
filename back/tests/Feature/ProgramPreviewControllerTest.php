<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramAlertReconciliation;
use App\Models\ProgramTarget;
use App\Models\UserProfile;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Models\OptOut;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\ProgramAlertPreviewService;
use App\Services\ProgramAlerts\ProgramAlertReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProgramAlertFixture;
use Tests\TestCase;

class ProgramPreviewControllerTest extends TestCase
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

    private function url(string $suffix = ''): string
    {
        return "/api/v1/vets/{$this->vet->guid}/programs{$suffix}";
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'alerts' => Alert::count(),
            'recipients' => AlertRecipient::count(),
            'audit' => ProgramAlertReconciliation::count(),
            'programs' => Program::count(),
            'targets' => ProgramTarget::count(),
        ];
    }

    public function test_preview_returns_summary_items_and_masked_destination(): void
    {
        $owner = $this->makeResponsible(whatsapp: '5491122334455', name: 'María Pérez');

        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10']], [$owner]));

        $response->assertOk()
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.timezone', 'America/Argentina/Buenos_Aires')
            ->assertJsonPath('data.template.task_due_version', 1)
            ->assertJsonPath('data.summary.alerts_total', 1)
            ->assertJsonPath('data.summary.alerts_will_send', 1)
            ->assertJsonPath('data.summary.alerts_wont_send', 0)
            ->assertJsonPath('data.summary.recipients_total', 1)
            ->assertJsonPath('data.summary.groups_count', 1)
            ->assertJsonPath('data.summary.window.from', '2030-06-10')
            ->assertJsonPath('data.program.company_name', 'Vet Test')
            ->assertJsonPath('data.program.client.name', 'Cliente Test')
            ->assertJsonPath('data.program.technique.name', 'IATF')
            ->assertJsonPath('data.diff', null)
            ->assertJsonPath('data.items.0.target_ref', '0')
            ->assertJsonPath('data.items.0.scheduled_at_local.time', '08:30')
            ->assertJsonPath('data.items.0.scheduled_at_utc', '2030-06-10T11:30:00Z')
            ->assertJsonPath('data.items.0.will_send', true)
            ->assertJsonPath('data.items.0.recipients.0.name', 'María Pérez')
            ->assertJsonPath('data.items.0.recipients.0.channel', 'whatsapp')
            ->assertJsonPath('data.items.0.recipients.0.destination_masked', '+54 ••• •••• 4455')
            ->assertJsonPath('data.items.0.recipients.0.contact_status', 'ok')
            ->assertJsonPath('data.items.0.recipients.0.rendered_message', 'Hola María Pérez, del programa "Protocolo Test": Traer termo');
    }

    public function test_preview_rejects_unbounded_targets_and_managers(): void
    {
        $owner = $this->makeResponsible();
        $targets = array_map(fn (int $i) => ['target_date' => '2030-06-10'], range(1, 101));

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload($targets, [$owner]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['targets']);

        $guids = array_map(fn () => Str::uuid()->toString(), range(1, 51));
        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10']], [$owner], ['manager_profile_ids' => $guids]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['manager_profile_ids']);
    }

    public function test_both_preview_routes_are_throttled(): void
    {
        foreach (['v1/vets/{vet}/programs/preview', 'v1/vets/{vet}/programs/{guid}/preview'] as $uri) {
            $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === "api/{$uri}");
            $this->assertNotNull($route, $uri);
            $this->assertContains('throttle:30,1', $route->gatherMiddleware());
        }
    }

    public function test_no_phone_number_ever_leaves_unmasked(): void
    {
        $owner = $this->makeResponsible(whatsapp: '5491122334455', email: 'maria.perez@example.com');
        $noWhatsapp = $this->makeResponsible($this->managerRole, whatsapp: null, email: 'juan.lopez@example.com');

        $json = $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10']], [$owner, $noWhatsapp]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('5491122334455', $json);
        $this->assertStringNotContainsString('maria.perez@example.com', $json);
        $this->assertStringNotContainsString('juan.lopez@example.com', $json);
        $withoutGuids = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '', $json);
        $this->assertSame(0, preg_match('/\d{5,}/', $withoutGuids), 'no run of more than 4 consecutive digits');
        $this->assertStringContainsString('j•••@example.com', json_encode(json_decode($json, true), JSON_UNESCAPED_UNICODE));
    }

    public function test_preview_resolves_the_group_placeholder_from_the_draft_name_or_the_fallback(): void
    {
        $this->protocolAlert->update(['message' => 'Grupo [grupo] de [establecimiento]']);
        $owner = $this->makeResponsible();

        $response = $this->actingAs($this->vetUser, 'sanctum')->postJson($this->url('/preview'), $this->draftPayload([
            ['target_date' => '2030-06-10', 'name' => 'Lote Norte'],
            ['target_date' => '2030-06-20', 'target_ref' => 'n2', 'animals_count' => 2],
            ['target_date' => '2030-06-30', 'target_ref' => 'n3', 'animals' => [['rp' => 'A1']]],
        ], [$owner]))->assertOk();

        $names = collect($response->json('data.items'))->pluck('target_name', 'target_date');
        $this->assertSame('Lote Norte', $names['2030-06-10']);
        $this->assertNull($names['2030-06-20']);

        $texts = collect($response->json('data.items'))->pluck('free_text', 'target_date');
        $this->assertSame('Grupo Lote Norte de Estancia Test', $texts['2030-06-10']);
        $this->assertSame('Grupo del 20/06/2030 (2 animales) de Estancia Test', $texts['2030-06-20']);
        $this->assertSame('Grupo del 30/06/2030 (1 animal) de Estancia Test', $texts['2030-06-30']);
    }

    public function test_preview_rejects_a_group_name_over_100_characters(): void
    {
        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10', 'name' => str_repeat('a', 101)]], [$this->makeResponsible()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['targets.0.name']);
    }

    public function test_preview_writes_nothing_and_queues_nothing(): void
    {
        Queue::fake();
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible()]);
        app(ProgramAlertReconciler::class)->reconcile($program, 'program_created');
        $before = $this->counts();
        $payload = $this->draftPayload(
            [['target_date' => '2030-06-12', 'guid' => $program->targets->first()->guid], ['target_date' => '2030-08-01', 'target_ref' => 'n1']],
            [$this->makeResponsible(whatsapp: null)],
        );
        $before['recipients'] = AlertRecipient::count();

        $this->actingAs($this->vetUser, 'sanctum')->postJson($this->url('/preview'), $payload)->assertOk();
        $this->actingAs($this->vetUser, 'sanctum')->postJson($this->url("/{$program->guid}/preview"), $payload)->assertOk();

        $this->assertSame($before, $this->counts());
        Queue::assertNothingPushed();
    }

    public function test_preview_matches_what_reconcile_creates_and_what_the_detail_projects(): void
    {
        $a = $this->makeResponsible(name: 'Ana');
        $b = $this->makeResponsible($this->managerRole, name: 'Beto');
        $this->makeProtocolAlert($this->task, '10:00', ['client-owner'], 'segundo aviso');
        $payload = $this->draftPayload([['target_date' => '2030-06-10'], ['target_date' => '2030-07-15']], [$a, $b]);

        $preview = $this->actingAs($this->vetUser, 'sanctum')->postJson($this->url('/preview'), $payload)->assertOk()->json('data');

        $expected = [];
        foreach ($preview['items'] as $item) {
            if ($item['will_send']) {
                $guids = collect($item['recipients'])->where('will_send', true)->pluck('profile_guid')->sort()->values()->all();
                $expected[] = $item['scheduled_at_utc'] . '|' . implode(',', $guids);
            }
        }
        sort($expected);

        $created = $this->actingAs($this->vetUser, 'sanctum')->postJson($this->url(), $payload)->assertCreated();

        $actual = Alert::where('type', AlertType::ProgramTaskDue)->with('recipients.userProfile')->get()->map(fn (Alert $alert) => $alert->scheduled_at->utc()->format('Y-m-d\TH:i:s\Z') . '|' . $alert->recipients
            ->where('channel.value', 'whatsapp')->pluck('userProfile.guid')->sort()->values()->implode(','))->sort()->values()->all();

        $this->assertSame($expected, $actual);
        $this->assertCount(4, $actual);

        // The detail view projects the same send dates.
        $detail = $this->actingAs($this->vetUser, 'sanctum')->getJson($this->url('/' . $created->json('data.guid')))->assertOk()->json('data.targets');
        $projected = collect($detail)->flatMap(fn ($t) => $t['tasks'])->flatMap(fn ($task) => $task['alerts'])
            ->map(fn ($alert) => $alert['scheduled_at_local']['date'] . ' ' . $alert['scheduled_at_local']['time'])->sort()->values()->all();
        $previewed = collect($preview['items'])->map(fn ($i) => $i['scheduled_at_local']['date'] . ' ' . $i['scheduled_at_local']['time'])->sort()->values()->all();
        $this->assertSame($previewed, $projected);
    }

    public function test_preview_reports_past_alerts_as_not_sent_with_the_critical_warning(): void
    {
        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-05-20']], [$this->makeResponsible()]))
            ->assertOk()
            ->assertJsonPath('data.items.0.will_send', false)
            ->assertJsonPath('data.items.0.skip_reason', 'in_past')
            ->assertJsonPath('data.summary.alerts_will_send', 0)
            ->assertJsonPath('data.summary.alerts_wont_send', 1)
            ->assertJsonPath('data.summary.window.from', null);

        $this->assertSame('ALERT_IN_PAST', $response->json('data.warnings.0.code'));
        $this->assertSame('critical', $response->json('data.warnings.0.severity'));
        $this->assertSame('0', $response->json('data.warnings.0.context.target_ref'));
    }

    public function test_item_status_per_recipient_covers_email_only_missing_invalid_and_opted_out(): void
    {
        $emailOnly = $this->makeResponsible(whatsapp: null, email: 'a@example.com', name: 'Email');
        $missing = $this->makeResponsible(whatsapp: null, name: 'Missing');
        $invalid = $this->makeResponsible(whatsapp: '12345', name: 'Invalid');
        $optedOut = $this->makeResponsible(whatsapp: '5491166667777', email: 'o@example.com', name: 'Optout');
        OptOut::create(['phone' => '5491166667777', 'channel' => 'whatsapp']);

        $recipients = collect($this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10']], [$emailOnly, $missing, $invalid, $optedOut]))
            ->assertOk()
            ->json('data.items.0.recipients'))->keyBy('name');

        $this->assertSame(['email', 'email_only', true, null], [$recipients['Email']['channel'], $recipients['Email']['contact_status'], $recipients['Email']['will_send'], $recipients['Email']['rendered_message']]);
        $this->assertSame(['missing', false, 'no_whatsapp_contact'], [$recipients['Missing']['contact_status'], $recipients['Missing']['will_send'], $recipients['Missing']['skip_reason']]);
        $this->assertSame(['invalid_number', false, 'invalid_number'], [$recipients['Invalid']['contact_status'], $recipients['Invalid']['will_send'], $recipients['Invalid']['skip_reason']]);
        $this->assertSame(['opted_out', false, 'opted_out'], [$recipients['Optout']['contact_status'], $recipients['Optout']['will_send'], $recipients['Optout']['skip_reason']]);
    }

    public function test_preview_warnings_include_email_only_as_non_critical_and_duplicate_dates(): void
    {
        $person = $this->makeResponsible(whatsapp: null, email: 'a@example.com');

        $warnings = collect($this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10'], ['target_date' => '2030-06-10']], [$person]))
            ->assertOk()
            ->json('data.warnings'))->keyBy('code');

        $this->assertSame('warning', $warnings['RECIPIENT_EMAIL_ONLY']['severity']);
        $this->assertSame('warning', $warnings['DUPLICATE_TARGET_DATE']['severity']);
        $this->assertFalse($warnings->has('RECIPIENT_WITHOUT_WHATSAPP'));
    }

    public function test_preview_is_truncated_above_the_cap_but_keeps_the_full_summary(): void
    {
        config(['notifications.preview_max_items' => 1]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10'], ['target_date' => '2030-07-10']], [$this->makeResponsible()]))
            ->assertOk()
            ->assertJsonPath('data.truncated', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.alerts_total', 2)
            ->assertJsonPath('data.summary.alerts_will_send', 2);
    }

    public function test_the_preview_is_tenant_scoped(): void
    {
        $otherVet = $this->makeVet('Otra Vet');
        $foreignClient = $this->makeClient('Ajeno');
        $foreignManager = $this->makeResponsible(client: $foreignClient);
        $owner = $this->makeResponsible();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10']], [$owner], ['client_id' => $foreignClient->guid]))
            ->assertStatus(422)->assertJsonValidationErrors(['client_id']);

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10']], [$foreignManager]))
            ->assertStatus(422)->assertJsonValidationErrors(['manager_profile_ids.0']);

        $foreignProtocol = \App\Models\Protocol::create([
            'guid' => Str::uuid()->toString(), 'technique_id' => $this->technique->id, 'vet_id' => $otherVet->id,
            'created_by_type' => 'vet', 'created_by_id' => 1, 'name' => 'Ajeno',
        ]);
        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload([['target_date' => '2030-06-10']], [$owner], ['protocol_id' => $foreignProtocol->guid]))
            ->assertStatus(422)->assertJsonValidationErrors(['protocol_id']);
    }

    public function test_edit_preview_of_a_program_of_another_vet_is_404_and_foreign_target_guids_are_422(): void
    {
        $owner = $this->makeResponsible();
        $mine = $this->makeProgram(['2030-06-10'], [$owner]);
        $other = $this->makeProgram(['2030-06-11'], [$owner]);
        $other->update(['vet_id' => $this->makeVet('Otra Vet')->id]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url("/{$other->guid}/preview"), $this->draftPayload([['target_date' => '2030-06-10']], [$owner]))
            ->assertNotFound();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/' . Str::uuid() . '/preview'), $this->draftPayload([['target_date' => '2030-06-10']], [$owner]))
            ->assertNotFound();

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url("/{$mine->guid}/preview"), $this->draftPayload([['target_date' => '2030-06-10', 'guid' => $other->targets()->first()->guid]], [$owner]))
            ->assertStatus(422)->assertJsonValidationErrors(['targets.0.guid']);
    }

    public function test_edit_preview_of_a_cancelled_program_is_not_editable(): void
    {
        $owner = $this->makeResponsible();
        $program = $this->makeProgram(['2030-06-10'], [$owner]);
        $program->update(['cancelled_at' => now()]);

        $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url("/{$program->guid}/preview"), $this->draftPayload([['target_date' => '2030-06-10', 'guid' => $program->targets->first()->guid]], [$owner]))
            ->assertStatus(422)->assertJsonPath('errors.reason', 'not_editable');
    }

    public function test_permissions_are_required(): void
    {
        $owner = $this->makeResponsible();
        $program = $this->makeProgram(['2030-06-10'], [$owner]);
        $assistant = $this->makeStaffUser($this->vet, 'vet-assistant'); // no programs.* permission
        $payload = $this->draftPayload([['target_date' => '2030-06-10']], [$owner]);

        $this->actingAs($assistant, 'sanctum')->postJson($this->url('/preview'), $payload)->assertForbidden();
        $this->actingAs($assistant, 'sanctum')->postJson($this->url("/{$program->guid}/preview"), $payload)->assertForbidden();
    }

    public function test_edit_diff_reports_rescheduled_cancelled_and_new_groups(): void
    {
        $owner = $this->makeResponsible();
        $program = $this->makeProgram(['2030-06-10', '2030-09-10'], [$owner]);
        app(ProgramAlertReconciler::class)->reconcile($program, 'program_created');
        [$a, $b] = [$program->targets[0], $program->targets[1]];

        $diff = $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url("/{$program->guid}/preview"), $this->draftPayload([
                ['guid' => $a->guid, 'target_date' => '2030-06-12'], // rescheduled
                ['target_ref' => 'new-1', 'target_date' => '2030-10-10'], // new group; B is removed
            ], [$owner]))
            ->assertOk()
            ->json('data.diff');

        $this->assertSame(0, $diff['kept']);
        $this->assertSame(1, $diff['created']);
        $this->assertSame(1, $diff['cancelled']);
        $this->assertCount(1, $diff['rescheduled']);
        $this->assertSame('2030-06-10', $diff['rescheduled'][0]['from']['date']);
        $this->assertSame('2030-06-12', $diff['rescheduled'][0]['to']['date']);
        $this->assertSame($a->guid, $diff['rescheduled'][0]['target_guid']);
        $this->assertSame('group_removed', $diff['cancelled_items'][0]['reason']);
        $this->assertSame('2030-09-10', $diff['cancelled_items'][0]['scheduled_at_local']['date']);
        $this->assertFalse($diff['protocol_changed']);
        $this->assertSame([], $diff['sent_not_modified']);
    }

    public function test_edit_diff_keeps_unchanged_groups_and_lists_already_sent_alerts_with_the_previous_date(): void
    {
        $owner = $this->makeResponsible();
        $other = $this->makeResponsible($this->managerRole);
        $program = $this->makeProgram(['2030-06-10', '2030-09-10'], [$owner, $other]);
        app(ProgramAlertReconciler::class)->reconcile($program, 'program_created');
        [$a, $b] = [$program->targets[0], $program->targets[1]];
        Alert::where('payload->programTargetGuid', $a->guid)->update(['status' => 'dispatched']); // already sent

        $diff = $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url("/{$program->guid}/preview"), $this->draftPayload([
                ['guid' => $a->guid, 'target_date' => '2030-06-15'],
                ['guid' => $b->guid, 'target_date' => '2030-09-10'],
            ], [$owner, $other]))
            ->assertOk()
            ->json('data.diff');

        $this->assertSame(1, $diff['kept']);
        $this->assertSame(1, $diff['created']); // the new date of the group that was already notified
        $this->assertSame(0, $diff['cancelled']);
        $this->assertSame([], $diff['rescheduled']);
        $this->assertCount(1, $diff['sent_not_modified']);
        $this->assertSame('2030-06-10', $diff['sent_not_modified'][0]['target_date_previous']);
        $this->assertSame(2, $diff['sent_not_modified'][0]['recipients_count']);
        $this->assertSame('Colecta de embriones', $diff['sent_not_modified'][0]['task_description']);
    }

    public function test_store_and_update_return_authoritative_warnings_and_the_reconciliation_summary(): void
    {
        $noContact = $this->makeResponsible(whatsapp: null, name: 'Sin Whatsapp');

        $stored = $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url(), $this->draftPayload([['target_date' => '2030-06-10'], ['target_date' => '2030-05-20']], [$noContact]))
            ->assertCreated();

        $codes = collect($stored->json('data.warnings'))->pluck('code')->all();
        $this->assertContains('RECIPIENT_WITHOUT_WHATSAPP', $codes);
        $this->assertContains('ALERT_IN_PAST', $codes); // the past group's alert was not created
        $this->assertSame(
            ['kept' => 0, 'rescheduled' => 0, 'created' => 1, 'cancelled' => 0, 'already_sent_not_modified' => 0],
            $stored->json('data.reconciliation'),
        );

        $guid = $stored->json('data.guid');
        $targets = $stored->json('data.targets');
        $updated = $this->actingAs($this->vetUser, 'sanctum')
            ->putJson($this->url("/{$guid}"), $this->draftPayload([
                ['guid' => $targets[0]['guid'], 'target_date' => '2030-06-12'], // was the past group: now future
                ['guid' => $targets[1]['guid'], 'target_date' => '2030-05-21'], // had the alert: now past
            ], [$noContact]))
            ->assertOk();

        // One group moved into the past (its alert is cancelled, nothing recreated); the other moved to
        // a future date (new alert). Different groups: not a reschedule pair.
        $this->assertSame(
            ['kept' => 0, 'rescheduled' => 0, 'created' => 1, 'cancelled' => 1, 'already_sent_not_modified' => 0],
            $updated->json('data.reconciliation'),
        );
        $this->assertIsArray($updated->json('data.warnings'));
    }

    public function test_the_program_detail_does_not_carry_warnings(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible()]);

        $data = $this->actingAs($this->vetUser, 'sanctum')->getJson($this->url("/{$program->guid}"))->assertOk()->json('data');

        $this->assertArrayNotHasKey('warnings', $data);
        $this->assertArrayNotHasKey('reconciliation', $data);
    }

    public function test_a_realistic_size_preview_completes(): void
    {
        // 10 groups x 8 tasks x 3 alerts x 4 people (timing is measured by hand, not asserted).
        foreach (range(2, 8) as $n) {
            $this->makeTask("Tarea {$n}", $n);
        }
        foreach (\App\Models\ProtocolTask::where('protocol_id', $this->protocol->id)->get() as $task) {
            $existing = $task->alerts()->count();
            foreach (range($existing + 1, 3) as $n) {
                $this->makeProtocolAlert($task, sprintf('%02d:00', 8 + $n), ['client-owner', 'client-manager'], "aviso {$n}");
            }
        }
        $people = array_map(fn ($i) => $this->makeResponsible($i % 2 ? $this->ownerRole : $this->managerRole, name: "Persona {$i}"), range(1, 4));
        $targets = array_map(fn ($i) => ['target_date' => Carbon::parse('2030-07-01')->addDays($i * 3)->toDateString()], range(0, 9));

        $data = $this->actingAs($this->vetUser, 'sanctum')
            ->postJson($this->url('/preview'), $this->draftPayload($targets, $people))
            ->assertOk()
            ->json('data');

        $this->assertSame(240, $data['summary']['alerts_total']);
        $this->assertSame(240, $data['summary']['alerts_will_send']);
        $this->assertSame(4, $data['summary']['recipients_total']);
        $this->assertCount(240, $data['items']);
    }

    public function test_service_preview_for_a_projection_input_matches_the_http_endpoint_shape(): void
    {
        $owner = $this->makeResponsible();
        $program = $this->makeProgram(['2030-06-10'], [$owner]);
        $in = ProjectionInput::fromProgram($program, \Carbon\CarbonImmutable::instance(now()));

        $preview = app(ProgramAlertPreviewService::class)->preview($in, null, $this->client, $this->establishment)->toArray();

        $this->assertSame(1, $preview['summary']['alerts_total']);
        $this->assertNull($preview['diff']);
        $this->assertInstanceOf(UserProfile::class, $owner);
    }
}
