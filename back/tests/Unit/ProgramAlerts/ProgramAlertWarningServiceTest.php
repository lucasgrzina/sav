<?php

namespace Tests\Unit\ProgramAlerts;

use App\Models\Program;
use App\Notifications\Models\OptOut;
use App\Services\ProgramAlerts\Data\ExistingAlertIndex;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\Data\Warning;
use App\Services\ProgramAlerts\Data\WarningMode;
use App\Services\ProgramAlerts\ProgramAlertProjector;
use App\Services\ProgramAlerts\ProgramAlertReconciler;
use App\Services\ProgramAlerts\ProgramAlertWarningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsProgramAlertFixture;
use Tests\TestCase;

class ProgramAlertWarningServiceTest extends TestCase
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

    /** @return list<Warning> */
    private function warnings(Program $program, WarningMode $mode = WarningMode::Save): array
    {
        $in = ProjectionInput::fromProgram($program, CarbonImmutable::instance(now()));

        return app(ProgramAlertWarningService::class)->build(
            $in,
            (new ProgramAlertProjector())->project($in),
            $mode,
            ExistingAlertIndex::forProgram($program),
        );
    }

    /**
     * @param list<Warning> $warnings
     * @return list<Warning>
     */
    private function ofCode(array $warnings, string $code): array
    {
        return array_values(array_filter($warnings, fn (Warning $w) => $w->code->value === $code));
    }

    public function test_a_clean_program_has_no_warnings(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible()]);

        $this->assertSame([], $this->warnings($program));
    }

    public function test_role_without_recipient_lists_the_roles_and_is_critical(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible($this->role('client-viewer'))]);

        $found = $this->ofCode($this->warnings($program), 'ALERT_ROLE_WITHOUT_RECIPIENT');

        $this->assertCount(1, $found);
        $this->assertSame('critical', $found[0]->severity->value);
        $this->assertSame(['client-owner', 'client-manager'], $found[0]->context['roles']);
        $this->assertSame($this->task->guid, $found[0]->context['protocol_task_guid']);
        $this->assertCount(0, $this->ofCode($this->warnings($program), 'ALERT_IN_PAST'));
    }

    public function test_role_without_recipient_is_not_reported_as_in_the_past_too(): void
    {
        $program = $this->makeProgram(['2030-05-20'], [$this->makeResponsible($this->role('client-viewer'))]);

        $warnings = $this->warnings($program);

        $this->assertCount(1, $this->ofCode($warnings, 'ALERT_ROLE_WITHOUT_RECIPIENT'));
        $this->assertCount(0, $this->ofCode($warnings, 'ALERT_IN_PAST'));
    }

    public function test_no_managers_selected_is_critical(): void
    {
        $program = $this->makeProgram(['2030-06-10'], []);

        $found = $this->ofCode($this->warnings($program), 'NO_MANAGERS_SELECTED');

        $this->assertCount(1, $found);
        $this->assertSame('critical', $found[0]->severity->value);
    }

    public function test_alert_in_the_past_without_an_existing_alert_is_critical_and_carries_local_time_and_group(): void
    {
        $program = $this->makeProgram(['2030-05-20'], [$this->makeResponsible()]);

        $found = $this->ofCode($this->warnings($program), 'ALERT_IN_PAST');

        $this->assertCount(1, $found);
        $this->assertSame('critical', $found[0]->severity->value);
        $this->assertSame('2030-05-20', $found[0]->context['scheduled_at_local']['date']);
        $this->assertSame('08:30', $found[0]->context['scheduled_at_local']['time']);
        $this->assertSame($program->targets->first()->guid, $found[0]->context['target_guid']);
    }

    public function test_alert_in_the_past_is_not_reported_when_an_alert_already_exists_for_it(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible()]);
        app(ProgramAlertReconciler::class)->reconcile($program, 'program_created');

        Carbon::setTestNow('2030-06-20 12:00:00'); // overdue, but its alert still exists (pending)

        $this->assertCount(0, $this->ofCode($this->warnings($program->fresh()), 'ALERT_IN_PAST'));
    }

    public function test_same_day_alert_is_past_after_its_hour_and_valid_30_seconds_ahead(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible()]);

        Carbon::setTestNow('2030-06-10 14:00:00'); // the 08:30 local alert (11:30 UTC) already passed
        $this->assertCount(1, $this->ofCode($this->warnings($program), 'ALERT_IN_PAST'));

        Carbon::setTestNow('2030-06-10 11:29:30'); // 30 s before 11:30 UTC
        $this->assertCount(0, $this->ofCode($this->warnings($program), 'ALERT_IN_PAST'));
    }

    public function test_tab_mode_downgrades_past_alerts_to_info(): void
    {
        $program = $this->makeProgram(['2030-05-20'], [$this->makeResponsible()]);

        $found = $this->ofCode($this->warnings($program, WarningMode::Tab), 'ALERT_IN_PAST');

        $this->assertSame('info', $found[0]->severity->value);
    }

    public function test_past_items_that_will_not_be_created_do_not_raise_recipient_warnings(): void
    {
        $program = $this->makeProgram(['2030-05-20'], [$this->makeResponsible(whatsapp: null)]);

        $this->assertCount(0, $this->ofCode($this->warnings($program, WarningMode::Tab), 'RECIPIENT_WITHOUT_WHATSAPP'));
        $this->assertCount(0, $this->ofCode($this->warnings($program, WarningMode::Save), 'RECIPIENT_WITHOUT_WHATSAPP'));
    }

    public function test_person_without_whatsapp_and_without_email_is_critical(): void
    {
        $profile = $this->makeResponsible(whatsapp: null, name: 'Sin Contacto');
        $program = $this->makeProgram(['2030-06-10'], [$profile]);

        $found = $this->ofCode($this->warnings($program), 'RECIPIENT_WITHOUT_WHATSAPP');

        $this->assertCount(1, $found);
        $this->assertSame('critical', $found[0]->severity->value);
        $this->assertFalse($found[0]->context['email_fallback']);
        $this->assertSame('Sin Contacto', $found[0]->context['name']);
        $this->assertSame($profile->guid, $found[0]->context['profile_guid']);
        $this->assertCount(0, $this->ofCode($this->warnings($program), 'RECIPIENT_EMAIL_ONLY'));
    }

    public function test_person_without_whatsapp_but_with_email_only_gets_the_non_critical_email_warning(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible(whatsapp: null, email: 'a@example.com')]);

        $warnings = $this->warnings($program);

        $this->assertCount(0, $this->ofCode($warnings, 'RECIPIENT_WITHOUT_WHATSAPP'));
        $found = $this->ofCode($warnings, 'RECIPIENT_EMAIL_ONLY');
        $this->assertCount(1, $found);
        $this->assertSame('warning', $found[0]->severity->value);
        $this->assertTrue($found[0]->context['email_fallback']);
    }

    public function test_invalid_number_and_opted_out_are_critical(): void
    {
        $invalid = $this->makeResponsible(whatsapp: '12345');
        $optedOut = $this->makeResponsible(whatsapp: '5491122334455');
        OptOut::create(['phone' => '5491122334455', 'channel' => 'whatsapp']);
        $program = $this->makeProgram(['2030-06-10'], [$invalid, $optedOut]);

        $warnings = $this->warnings($program);

        $this->assertSame($invalid->guid, $this->ofCode($warnings, 'RECIPIENT_INVALID_NUMBER')[0]->context['profile_guid']);
        $this->assertSame($optedOut->guid, $this->ofCode($warnings, 'RECIPIENT_OPTED_OUT')[0]->context['profile_guid']);
        $this->assertSame('critical', $this->ofCode($warnings, 'RECIPIENT_OPTED_OUT')[0]->severity->value);
    }

    public function test_duplicate_target_date_warns_once_with_both_groups(): void
    {
        $program = $this->makeProgram(['2030-06-10', '2030-06-10', '2030-07-10'], [$this->makeResponsible()]);

        $found = $this->ofCode($this->warnings($program), 'DUPLICATE_TARGET_DATE');

        $this->assertCount(1, $found);
        $this->assertSame('warning', $found[0]->severity->value);
        $this->assertSame('2030-06-10', $found[0]->context['date']);
        $this->assertSame(2, $found[0]->context['count']);
    }

    public function test_high_volume_uses_the_configurable_threshold_per_person_and_day(): void
    {
        $this->makeProtocolAlert($this->task, '09:00', ['client-owner'], 'b');
        $this->makeProtocolAlert($this->task, '10:00', ['client-owner'], 'c');
        $this->makeProtocolAlert($this->task, '11:00', ['client-owner'], 'd'); // 4 alerts the same day
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible(name: 'Saturada')]);

        $found = $this->ofCode($this->warnings($program), 'HIGH_VOLUME_FOR_RECIPIENT');

        $this->assertCount(1, $found);
        $this->assertSame(4, $found[0]->context['count']);
        $this->assertSame('2030-06-10', $found[0]->context['date']);
        $this->assertSame('warning', $found[0]->severity->value);

        config(['notifications.high_volume_per_day' => 4]);
        $this->assertCount(0, $this->ofCode($this->warnings($program), 'HIGH_VOLUME_FOR_RECIPIENT'));
    }

    public function test_task_without_alerts_is_info(): void
    {
        $lonely = $this->makeTask('Tarea sin avisos', 2);
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible()]);

        $found = $this->ofCode($this->warnings($program), 'TASK_WITHOUT_ALERTS');

        $this->assertCount(1, $found);
        $this->assertSame('info', $found[0]->severity->value);
        $this->assertSame(1, $found[0]->context['count']);
        $this->assertSame(
            [['protocol_task_guid' => $lonely->guid, 'description' => 'Tarea sin avisos']],
            $found[0]->context['items'],
        );
    }

    public function test_no_task_without_alerts_yields_no_warning(): void
    {
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible()]);

        $this->assertCount(0, $this->ofCode($this->warnings($program), 'TASK_WITHOUT_ALERTS'));
    }

    public function test_many_tasks_without_alerts_collapse_into_one_warning(): void
    {
        foreach (range(1, 3) as $n) {
            $this->makeTask("Tarea {$n}", $n + 1);
        }
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible()]);

        $found = $this->ofCode($this->warnings($program), 'TASK_WITHOUT_ALERTS');

        $this->assertCount(1, $found);
        $this->assertSame(3, $found[0]->context['count']);
        $this->assertSame(['Tarea 1', 'Tarea 2', 'Tarea 3'], array_column($found[0]->context['items'], 'description'));
    }

    public function test_tasks_without_alerts_items_are_capped_but_count_is_true(): void
    {
        foreach (range(1, 13) as $n) {
            $this->makeTask("Tarea {$n}", $n + 1);
        }
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible()]);

        $found = $this->ofCode($this->warnings($program), 'TASK_WITHOUT_ALERTS');

        $this->assertCount(1, $found);
        $this->assertSame(13, $found[0]->context['count']);
        $this->assertCount(ProgramAlertWarningService::MAX_TASKS_WITHOUT_ALERTS_ITEMS, $found[0]->context['items']);
    }

    public function test_more_than_five_warnings_of_one_code_in_one_group_collapse_into_one(): void
    {
        foreach (range(1, 5) as $n) {
            $this->makeProtocolAlert($this->task, sprintf('%02d:00', 9 + $n), ['client-owner'], "m{$n}");
        }
        // 6 alerts for one group; the only responsible has a role none of them targets.
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible($this->role('client-viewer'))]);

        $found = $this->ofCode($this->warnings($program), 'ALERT_ROLE_WITHOUT_RECIPIENT');

        $this->assertCount(1, $found);
        $this->assertSame(6, $found[0]->context['count']);
        $this->assertCount(6, $found[0]->context['items']);
    }

    public function test_up_to_five_warnings_stay_individual(): void
    {
        foreach (range(1, 4) as $n) {
            $this->makeProtocolAlert($this->task, sprintf('%02d:00', 9 + $n), ['client-owner'], "m{$n}");
        }
        $program = $this->makeProgram(['2030-06-10'], [$this->makeResponsible($this->role('client-viewer'))]);

        $this->assertCount(5, $this->ofCode($this->warnings($program), 'ALERT_ROLE_WITHOUT_RECIPIENT'));
    }

    public function test_warnings_are_ordered_critical_then_warning_then_info(): void
    {
        $this->makeTask('Tarea sin avisos', 2);
        $program = $this->makeProgram(['2030-06-10', '2030-06-10'], [$this->makeResponsible(whatsapp: null)]);

        $severities = array_map(fn (Warning $w) => $w->severity->value, $this->warnings($program));

        $this->assertSame(['critical', 'warning', 'info'], array_values(array_unique($severities)));
    }
}
