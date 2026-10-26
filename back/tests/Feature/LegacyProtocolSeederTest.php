<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Protocol;
use App\Models\ProtocolTaskAlert;
use App\Models\Role;
use App\Models\User;
use App\Services\ProtocolService;
use Database\Seeders\LegacyProtocolSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyProtocolSeederTest extends TestCase
{
    use RefreshDatabase;
    use WithoutModelEvents;

    private const LEGACY_FILES = [
        'moet_migration.json',
        'iatf_migration.json',
        'fiv_migration.json',
        'hernicol_iatf_migration.json',
    ];

    private const BOTH = ['client-manager', 'client-owner'];
    private const MGR  = ['client-manager'];

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => 'platform'],
        );

        $user = User::factory()->create();
        $user->assignRole($role);

        Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'AR',
            'phone_prefix' => '+54',
            'timezone'     => 'America/Argentina/Buenos_Aires',
        ]);

        (new LegacyProtocolSeeder())->run();
    }

    /**
     * Expected schedule per protocol, indexed by task sort_order:
     * [task day relative to D0, task time, alert day relative to D0 (null = no alert), alert roles].
     * Every alert fires at 17:00 on the calendar day before its task.
     */
    private function theriogenologySchedules(): array
    {
        return [
            'Papel de trabajo 1' => [
                0 => [-10, '18:00', -11, self::BOTH],
                1 => [-3, '18:00', -4, self::MGR],
                2 => [0, '06:00', -1, self::MGR],
                3 => [11, '10:00', 10, self::MGR],
                4 => [60, '10:00', 59, self::MGR],
                5 => [90, '10:00', 89, self::MGR],
            ],
            'Papel de trabajo 2' => [
                0 => [-10, '18:00', -11, self::BOTH],
                1 => [-3, '18:00', -4, self::MGR],
                2 => [0, '06:00', -1, self::MGR],
                3 => [0, '06:00', null, null],
                4 => [5, '10:00', 4, self::MGR],
                5 => [19, '10:00', 18, self::MGR],
                6 => [60, '10:00', 59, self::MGR],
                7 => [90, '10:00', 89, self::MGR],
            ],
            'Papel de trabajo 3' => [
                0 => [-10, '15:30', -11, self::BOTH],
                1 => [-3, '15:30', -4, self::MGR],
                2 => [0, '06:00', -1, self::MGR],
                3 => [1, '04:00', null, null],
                4 => [11, '10:00', 10, self::MGR],
                5 => [60, '10:00', 59, self::MGR],
                6 => [90, '10:00', 89, self::MGR],
            ],
        ];
    }

    public function test_theriogenology_protocols_are_created_with_expected_counts(): void
    {
        $schedules = $this->theriogenologySchedules();

        foreach ($schedules as $name => $schedule) {
            $protocol = Protocol::where('name', $name)->first();

            $this->assertNotNull($protocol, "Missing protocol {$name}");
            $this->assertSame('Vaquillonas - Carne', $protocol->technique->name);
            $this->assertSame('IATF', $protocol->technique->parent->name);
            $this->assertCount(count($schedule), $protocol->tasks, "Task count for {$name}");

            $expectedAlerts = count(array_filter($schedule, fn ($row) => $row[2] !== null));
            $this->assertSame(
                $expectedAlerts,
                ProtocolTaskAlert::whereIn('protocol_task_id', $protocol->tasks->pluck('id'))->count(),
                "Alert count for {$name}",
            );
        }

        $this->assertSame(3, Protocol::whereIn('name', array_keys($schedules))->distinct()->count('color'));
    }

    public function test_theriogenology_alerts_attach_to_the_right_task_with_the_right_direction(): void
    {
        $baseDate = Carbon::parse('2026-03-20');
        $service  = app(ProtocolService::class);

        foreach ($this->theriogenologySchedules() as $name => $schedule) {
            $protocol = Protocol::where('name', $name)->firstOrFail();
            $protocol->load('tasks.alerts');
            $simulation = $service->simulate($protocol, $baseDate);

            $tasksByGuid  = collect($simulation['tasks'])->keyBy('guid');
            $alertsByGuid = collect($simulation['alerts'])->keyBy('guid');

            foreach ($protocol->tasks->sortBy('sort_order') as $task) {
                [$taskDay, $taskTime, $alertDay, $alertRoles] = $schedule[$task->sort_order];

                $computed = $tasksByGuid[$task->guid];
                $this->assertSame(
                    $baseDate->copy()->addDays($taskDay)->toDateString(),
                    $computed['computed_date'],
                    "{$name} task #{$task->sort_order} date",
                );
                $this->assertSame($taskTime, $computed['computed_time'], "{$name} task #{$task->sort_order} time");

                if ($alertDay === null) {
                    $this->assertCount(0, $task->alerts, "{$name} task #{$task->sort_order} must have no alert");
                    continue;
                }

                $this->assertCount(1, $task->alerts, "{$name} task #{$task->sort_order} alerts");
                $alert = $task->alerts->first();

                $this->assertSame(1, $alert->offset_days);
                $this->assertSame('before', $alert->time_of_day);
                $this->assertSame($alertRoles, $alert->roles);

                $computedAlert = $alertsByGuid[$alert->guid];
                $this->assertSame(
                    $baseDate->copy()->addDays($alertDay)->toDateString(),
                    $computedAlert['computed_date'],
                    "{$name} task #{$task->sort_order} alert date",
                );
                $this->assertSame('17:00', $computedAlert['computed_time']);
            }
        }
    }

    public function test_protocol_3_has_two_insemination_tasks_and_a_single_alert_covering_both(): void
    {
        $protocol = Protocol::where('name', 'Papel de trabajo 3')->firstOrFail();
        $first    = $protocol->tasks()->where('sort_order', 2)->firstOrFail();
        $second   = $protocol->tasks()->where('sort_order', 3)->firstOrFail();

        $this->assertStringContainsString('Primer turno', $first->description);
        $this->assertStringContainsString('Segundo turno', $second->description);
        $this->assertSame(0, $first->days_offset);
        $this->assertSame(1, $second->days_offset);
        $this->assertSame('after', $second->time_of_day);

        $this->assertCount(1, $first->alerts);
        $this->assertStringContainsString('[fecha del día +1]', $first->alerts->first()->message);
        $this->assertStringContainsString('para el grupo [grupo] de [establecimiento]', $first->alerts->first()->message);
        $this->assertStringNotContainsString('[lote]', $first->alerts->first()->message);
        $this->assertCount(0, $second->alerts);
    }

    public function test_protocol_2_links_insemination_and_bull_entry_under_one_alert(): void
    {
        $protocol = Protocol::where('name', 'Papel de trabajo 2')->firstOrFail();
        $ia       = $protocol->tasks()->where('sort_order', 2)->firstOrFail();
        $bulls    = $protocol->tasks()->where('sort_order', 3)->firstOrFail();

        $this->assertSame($ia->days_offset, $bulls->days_offset);
        $this->assertSame($ia->time, $bulls->time);
        $this->assertCount(1, $ia->alerts);
        $this->assertStringContainsString('ingresan inmediatamente los toros', $ia->alerts->first()->message);
        $this->assertCount(0, $bulls->alerts);
    }

    public function test_legacy_json_files_still_seed_with_the_original_heuristic(): void
    {
        foreach (self::LEGACY_FILES as $file) {
            $data = json_decode(file_get_contents(base_path("database/scripts/{$file}")), true, flags: JSON_THROW_ON_ERROR);

            foreach ($data['protocols'] as $protocolData) {
                $protocol = Protocol::where('name', $protocolData['name'])->first();
                $this->assertNotNull($protocol, "{$file}: missing protocol {$protocolData['name']}");
                $this->assertCount(count($protocolData['tasks']), $protocol->tasks, "{$file}: {$protocolData['name']} tasks");

                $tasksBySortOrder = $protocol->tasks->keyBy('sort_order');
                $desc             = collect($protocolData['tasks'])->sortByDesc('days_offset');
                $alerts           = ProtocolTaskAlert::whereIn('protocol_task_id', $protocol->tasks->pluck('id'))->orderBy('id')->get();

                $this->assertCount(count($protocolData['alerts'] ?? []), $alerts, "{$file}: {$protocolData['name']} alerts");

                foreach ($protocolData['alerts'] ?? [] as $i => $alertData) {
                    // Original heuristic: first task in chronological order (desc days_offset) with days_offset <= the alert's; else the last one.
                    $expectedIndex = $desc->filter(fn ($t) => $t['days_offset'] <= $alertData['days_offset'])->keys()->first()
                        ?? $desc->keys()->last();

                    $this->assertSame(
                        $tasksBySortOrder[$expectedIndex]->id,
                        $alerts[$i]->protocol_task_id,
                        "{$file}: {$protocolData['name']} alert #{$i} attached to the wrong task",
                    );
                    $this->assertSame($alertData['text'], $alerts[$i]->message);
                }
            }
        }
    }
}
