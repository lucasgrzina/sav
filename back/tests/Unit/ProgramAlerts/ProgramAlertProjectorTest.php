<?php

namespace Tests\Unit\ProgramAlerts;

use App\Models\Country;
use App\Models\Protocol;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use App\Models\Role;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Enums\AlertType;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\Data\TargetInput;
use App\Services\ProgramAlerts\LogicalKey;
use App\Services\ProgramAlerts\ProgramAlertProjector;
use App\Support\LocalizedDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** Projector tests: models are built in memory, no database involved. */
class ProgramAlertProjectorTest extends TestCase
{
    private function vet(string $timezone, string $iso = 'AR'): Vet
    {
        $vet = new Vet();
        $vet->setRelation('country', (new Country())->forceFill(['iso_code' => $iso, 'timezone' => $timezone]));

        return $vet;
    }

    private function manager(string $guid, string $roleName): UserProfile
    {
        $profile = (new UserProfile())->forceFill(['guid' => $guid]);
        $profile->setRelation('role', (new Role())->forceFill(['name' => $roleName]));

        return $profile;
    }

    /** @param list<array<string, mixed>> $tasks */
    private function protocol(array $tasks): Protocol
    {
        $protocol = (new Protocol())->forceFill(['guid' => 'protocol-1']);

        $protocol->setRelation('tasks', new Collection(array_map(function (array $t) {
            $task = (new ProtocolTask())->forceFill([
                'guid' => $t['guid'], 'description' => 'Task ' . $t['guid'], 'days_offset' => $t['offset'],
                'time_of_day' => $t['tod'], 'time' => $t['time'], 'important' => false,
            ]);
            $task->setRelation('alerts', new Collection(array_map(
                fn (array $a) => (new ProtocolTaskAlert())->forceFill([
                    'guid' => $a['guid'], 'offset_days' => $a['offset'], 'time_of_day' => $a['tod'], 'time' => $a['time'],
                    'roles' => $a['roles'], 'message' => 'msg ' . $a['guid'], 'require_confirmation' => false,
                ]),
                $t['alerts']
            )));

            return $task;
        }, $tasks)));

        return $protocol;
    }

    private function input(Vet $vet, Protocol $protocol, array $targets, array $managers, string $now, ?string $programGuid = 'program-1'): ProjectionInput
    {
        return new ProjectionInput(
            vet: $vet,
            programGuid: $programGuid,
            protocol: $protocol,
            targets: $targets,
            managers: new Collection($managers),
            now: CarbonImmutable::parse($now, 'UTC'),
            locale: 'es',
        );
    }

    private function simpleProtocol(): Protocol
    {
        return $this->protocol([[
            'guid' => 'task-1', 'offset' => 0, 'tod' => 'after', 'time' => '08:00:00',
            'alerts' => [['guid' => 'alert-1', 'offset' => 1, 'tod' => 'before', 'time' => '09:00', 'roles' => ['client-owner']]],
        ]]);
    }

    public function test_task_and_alert_dates_apply_offset_and_time_of_day(): void
    {
        $protocol = $this->protocol([
            ['guid' => 'before', 'offset' => 3, 'tod' => 'before', 'time' => '08:00', 'alerts' => [
                ['guid' => 'a-before', 'offset' => 1, 'tod' => 'before', 'time' => '09:00', 'roles' => ['client-owner']],
            ]],
            ['guid' => 'after', 'offset' => 5, 'tod' => 'after', 'time' => '08:00', 'alerts' => [
                ['guid' => 'a-after', 'offset' => 2, 'tod' => 'after', 'time' => '10:30', 'roles' => ['client-owner']],
            ]],
        ]);

        $projection = (new ProgramAlertProjector())->project($this->input(
            $this->vet('America/Argentina/Buenos_Aires'), $protocol,
            [new TargetInput('t1', 'target-1', '2030-08-15')], [$this->manager('m1', 'client-owner')], '2030-01-01 00:00:00'
        ));

        $byGuid = [];
        foreach ($projection->items as $item) {
            $byGuid[$item->protocolTaskAlertGuid] = $item;
        }

        $this->assertSame('2030-08-12', $byGuid['a-before']->taskDate);
        $this->assertSame('2030-08-11', $byGuid['a-before']->scheduledAtLocal->date);
        $this->assertSame('2030-08-20', $byGuid['a-after']->taskDate);
        $this->assertSame('2030-08-22', $byGuid['a-after']->scheduledAtLocal->date);
        $this->assertSame('10:30', $byGuid['a-after']->scheduledAtLocal->time);
    }

    public function test_local_time_is_converted_to_utc_using_the_vet_timezone(): void
    {
        $projection = (new ProgramAlertProjector())->project($this->input(
            $this->vet('America/Argentina/Buenos_Aires'), $this->simpleProtocol(),
            [new TargetInput('t1', 'target-1', '2030-08-15')], [$this->manager('m1', 'client-owner')], '2030-01-01 00:00:00'
        ));

        $item = $projection->items[0];

        $this->assertSame('2030-08-14 12:00:00', $item->scheduledAtUtc->format('Y-m-d H:i:s')); // 09:00 at UTC-3
        $this->assertSame('2030-08-14T09:00:00-03:00', $item->scheduledAtLocal->iso);
        $this->assertSame('miércoles', $item->scheduledAtLocal->weekday);
        $this->assertSame('mié 14/08/2030 09:00', $item->scheduledAtLocal->label);
        $this->assertSame('America/Argentina/Buenos_Aires', $projection->timezone);
    }

    public function test_chile_daylight_saving_offsets_differ_between_seasons(): void
    {
        $projector = new ProgramAlertProjector();
        $vet = $this->vet('America/Santiago', 'CL');
        $managers = [$this->manager('m1', 'client-owner')];

        // Southern winter: UTC-4. Southern summer: UTC-3.
        $winter = $projector->project($this->input($vet, $this->simpleProtocol(), [new TargetInput('t', 't-1', '2030-07-15')], $managers, '2030-01-01 00:00:00'));
        $summer = $projector->project($this->input($vet, $this->simpleProtocol(), [new TargetInput('t', 't-1', '2030-01-15')], $managers, '2029-01-01 00:00:00'));

        $this->assertSame('13:00:00', $winter->items[0]->scheduledAtUtc->format('H:i:s'));
        $this->assertSame('12:00:00', $summer->items[0]->scheduledAtUtc->format('H:i:s'));
    }

    public function test_is_past_uses_the_single_now_of_the_projection(): void
    {
        $projector = new ProgramAlertProjector();
        $vet = $this->vet('UTC');
        $managers = [$this->manager('m1', 'client-owner')];
        $target = [new TargetInput('t', 't-1', '2030-08-15')]; // alert: 2030-08-14 09:00 UTC

        $this->assertTrue($projector->project($this->input($vet, $this->simpleProtocol(), $target, $managers, '2030-08-14 09:00:00'))->items[0]->isPast);
        $this->assertTrue($projector->project($this->input($vet, $this->simpleProtocol(), $target, $managers, '2030-08-14 09:00:01'))->items[0]->isPast);
        $this->assertFalse($projector->project($this->input($vet, $this->simpleProtocol(), $target, $managers, '2030-08-14 08:59:59'))->items[0]->isPast);
    }

    public function test_recipients_are_filtered_by_role_and_nothing_is_discarded(): void
    {
        $protocol = $this->simpleProtocol();
        $owner = $this->manager('owner', 'client-owner');
        $vetProfile = $this->manager('vet', 'vet');

        $projection = (new ProgramAlertProjector())->project($this->input(
            $this->vet('UTC'), $protocol, [new TargetInput('t', 't-1', '2030-08-15')], [$owner, $vetProfile], '2031-01-01 00:00:00'
        ));

        $this->assertCount(1, $projection->items);
        $this->assertTrue($projection->items[0]->isPast); // past but still projected
        $this->assertSame(['owner'], array_map(fn ($r) => $r->profile->guid, $projection->items[0]->recipients));

        $noRecipients = (new ProgramAlertProjector())->project($this->input(
            $this->vet('UTC'), $protocol, [new TargetInput('t', 't-1', '2030-08-15')], [$vetProfile], '2030-01-01 00:00:00'
        ));

        $this->assertCount(1, $noRecipients->items);
        $this->assertSame([], $noRecipients->items[0]->recipients);
    }

    public function test_multi_group_flag_and_logical_key(): void
    {
        $projector = new ProgramAlertProjector();
        $managers = [$this->manager('m1', 'client-owner')];

        $single = $projector->project($this->input($this->vet('UTC'), $this->simpleProtocol(), [new TargetInput('t1', 'g1', '2030-08-15')], $managers, '2030-01-01 00:00:00'));
        $double = $projector->project($this->input(
            $this->vet('UTC'), $this->simpleProtocol(),
            [new TargetInput('t1', 'g1', '2030-08-15'), new TargetInput('t2', 'g2', '2030-08-15')], $managers, '2030-01-01 00:00:00'
        ));

        $this->assertFalse($single->items[0]->multiGroup);
        $this->assertTrue($double->items[0]->multiGroup);

        $expected = LogicalKey::for('program-1', AlertType::ProgramTaskDue, 'g1', 'alert-1', CarbonImmutable::parse('2030-08-14 09:00:00', 'UTC'));
        $this->assertSame($expected, $single->items[0]->logicalKey);
        $this->assertSame(40, strlen($expected));
        $this->assertNotSame($double->items[0]->logicalKey, $double->items[1]->logicalKey); // same date, different group
    }

    public function test_logical_key_is_null_for_drafts_and_items_are_ordered_by_send_instant(): void
    {
        $protocol = $this->protocol([
            ['guid' => 'task-1', 'offset' => 0, 'tod' => 'after', 'time' => '08:00', 'alerts' => [
                ['guid' => 'late', 'offset' => 0, 'tod' => 'after', 'time' => '18:00', 'roles' => ['client-owner']],
                ['guid' => 'early', 'offset' => 1, 'tod' => 'before', 'time' => '07:00', 'roles' => ['client-owner']],
            ]],
            ['guid' => 'task-2', 'offset' => 0, 'tod' => 'after', 'time' => '08:00', 'alerts' => []],
        ]);

        $projection = (new ProgramAlertProjector())->project($this->input(
            $this->vet('UTC'), $protocol, [new TargetInput('draft-0', null, '2030-08-15')], [$this->manager('m1', 'client-owner')],
            '2030-01-01 00:00:00', programGuid: null
        ));

        $this->assertSame(['early', 'late'], array_map(fn ($i) => $i->protocolTaskAlertGuid, $projection->items));
        $this->assertNull($projection->items[0]->logicalKey);
        $this->assertSame([['protocolTaskGuid' => 'task-2', 'description' => 'Task task-2']], $projection->tasksWithoutAlerts);
        $this->assertCount(2, $projection->tasksForTarget('draft-0'));
        $this->assertSame(['draft-0'], array_keys($projection->byTarget()));
    }

    public function test_payload_v2_carries_the_context_snapshot(): void
    {
        $projection = (new ProgramAlertProjector())->project($this->input(
            $this->vet('UTC'), $this->simpleProtocol(), [new TargetInput('t1', 'g1', '2030-08-15')],
            [$this->manager('m1', 'client-owner')], '2030-01-01 00:00:00'
        ));

        $this->assertSame([
            'protocolTaskAlertGuid' => 'alert-1',
            'message' => 'msg alert-1',
            'programTargetGuid' => 'g1',
            'protocolTaskGuid' => 'task-1',
            'protocolGuid' => 'protocol-1',
            'targetDate' => '2030-08-15',
            'taskDescription' => 'Task task-1',
            'taskDate' => '2030-08-15',
            'taskTime' => '08:00',
            'multiGroup' => false,
            'contextVersion' => 2,
        ], $projection->items[0]->toPayload());
    }

    public function test_relative_days_labels(): void
    {
        $send = CarbonImmutable::parse('2030-08-14 09:00:00');

        foreach ([
            '2030-08-14' => 'hoy', '2030-08-15' => 'mañana', '2030-08-16' => 'pasado mañana', '2030-08-20' => 'en 6 días',
            '2030-08-13' => 'ayer', '2030-08-10' => 'hace 4 días',
        ] as $task => $expected) {
            $this->assertSame($expected, LocalizedDate::relativeDays(CarbonImmutable::parse($task), $send));
        }
    }

    public function test_locale_falls_back_to_the_configured_default(): void
    {
        $this->assertSame('es', ProjectionInput::localeFor($this->vet('UTC', 'MX')));
    }
}
