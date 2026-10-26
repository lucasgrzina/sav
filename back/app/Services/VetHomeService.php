<?php

namespace App\Services;

use App\Contracts\Repositories\VetHomeRepositoryInterface;
use App\Models\Program;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Services\ProgramAlerts\AlertLineStateResolver;
use App\Services\ProgramAlerts\Data\AlertLineState;
use App\Services\ProgramAlerts\Data\LocalDateTime;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use Carbon\CarbonImmutable;

/**
 * Aggregate behind the vet "Inicio" screen: upcoming programs, recent notices and notices with
 * problems. Delivery states come from AlertLineStateResolver (the same one the program "Avisos"
 * tab uses), never re-derived here. Not cached: it is per user (client profiles see a subset) and
 * the problems block is actionable.
 */
class VetHomeService
{
    public const UPCOMING_PROGRAMS_LIMIT = 5;
    public const RECENT_ALERTS_LIMIT = 8;
    public const PROBLEM_ALERTS_LIMIT = 8;

    /** Problem notices older than this are no longer "pending attention". */
    public const PROBLEM_WINDOW_DAYS = 30;

    /** Candidate pairs fetched per block: some pairs resolve to another bucket and are discarded. */
    private const CANDIDATE_FACTOR = 5;

    public function __construct(
        private VetHomeRepositoryInterface $repo,
        private AlertLineStateResolver $resolver,
    ) {}

    /**
     * @param bool $withAlerts whether the user may read delivery data (programs.alerts.read);
     *                         when false both alert blocks are null
     * @return array<string, mixed>
     */
    public function forProfile(Vet $vet, UserProfile $profile, bool $withAlerts): array
    {
        $vet->loadMissing('country');

        $now = CarbonImmutable::now();
        $timezone = $vet->timezone();
        $today = $now->setTimezone($timezone)->toDateString();

        // Client-side profiles only see the establishments they are linked to; vet staff see the whole tenant.
        $establishmentIds = $profile->authenticatable_type === 'client'
            ? $this->repo->establishmentIdsForProfile($profile)
            : null;

        $data = [
            'generated_at'      => $now->toIso8601ZuluString(),
            'timezone'          => $timezone,
            'upcoming_programs' => [],
            'recent_alerts'     => null,
            'problem_alerts'    => null,
            'has_more'          => ['upcoming_programs' => false, 'recent_alerts' => null, 'problem_alerts' => null],
        ];

        [$data['upcoming_programs'], $data['has_more']['upcoming_programs']] = $this->upcomingPrograms($vet, $today, $establishmentIds);

        if ($withAlerts) {
            $locale = ProjectionInput::localeFor($vet);
            [$data['recent_alerts'], $data['has_more']['recent_alerts']] = $this->recentAlerts($vet, $establishmentIds, $now, $timezone, $locale);
            [$data['problem_alerts'], $data['has_more']['problem_alerts']] = $this->problemAlerts($vet, $establishmentIds, $now, $timezone, $locale);
        }

        return $data;
    }

    /**
     * @param list<int>|null $establishmentIds
     * @return array{0: list<array<string, mixed>>, 1: bool} items and whether more exist beyond the limit
     */
    private function upcomingPrograms(Vet $vet, string $today, ?array $establishmentIds): array
    {
        // Ask for one extra row to know whether the list was cut.
        $programs = $this->repo->upcomingPrograms($vet->id, $today, $establishmentIds, self::UPCOMING_PROGRAMS_LIMIT + 1);

        $items = $programs
            ->take(self::UPCOMING_PROGRAMS_LIMIT)
            ->map(fn (Program $program) => $this->programSummary($program) + [
                'next_target_date' => substr((string) $program->next_target_date, 0, 10),
            ])
            ->values()
            ->all();

        return [$items, $programs->count() > self::UPCOMING_PROGRAMS_LIMIT];
    }

    /**
     * @param list<int>|null $establishmentIds
     * @return array{0: list<array<string, mixed>>, 1: bool} items and whether more exist beyond the limit
     */
    private function recentAlerts(Vet $vet, ?array $establishmentIds, CarbonImmutable $now, string $timezone, string $locale): array
    {
        $pairs = $this->repo->recentReachedPairs($vet->id, $establishmentIds, self::RECENT_ALERTS_LIMIT * self::CANDIDATE_FACTOR);

        $entries = $this->resolveEntries($pairs, $now, AlertLineState::BUCKET_SENT);

        usort($entries, fn (array $a, array $b) => $this->sentTimestamp($b) <=> $this->sentTimestamp($a));

        return [
            $this->present(array_slice($entries, 0, self::RECENT_ALERTS_LIMIT), $vet, $timezone, $locale, problem: false),
            count($entries) > self::RECENT_ALERTS_LIMIT,
        ];
    }

    /**
     * @param list<int>|null $establishmentIds
     * @return array{0: list<array<string, mixed>>, 1: bool} items and whether more exist beyond the limit
     */
    private function problemAlerts(Vet $vet, ?array $establishmentIds, CarbonImmutable $now, string $timezone, string $locale): array
    {
        $pairs = $this->repo->problemPairs(
            $vet->id,
            $establishmentIds,
            $now->subDays(self::PROBLEM_WINDOW_DAYS),
            $now,
            self::PROBLEM_ALERTS_LIMIT * self::CANDIDATE_FACTOR,
        );

        $entries = $this->resolveEntries($pairs, $now, AlertLineState::BUCKET_PROBLEM);

        usort($entries, fn (array $a, array $b) => $b['alert']->scheduled_at->getTimestamp() <=> $a['alert']->scheduled_at->getTimestamp());

        return [
            $this->present(array_slice($entries, 0, self::PROBLEM_ALERTS_LIMIT), $vet, $timezone, $locale, problem: true),
            count($entries) > self::PROBLEM_ALERTS_LIMIT,
        ];
    }

    /**
     * @param list<array{0: int, 1: int}> $pairs
     * @return list<array<string, mixed>> groups plus their resolved `state`, only those in `$bucket`
     */
    private function resolveEntries(array $pairs, CarbonImmutable $now, string $bucket): array
    {
        $entries = [];

        foreach ($this->repo->groupsForPairs($pairs) as $group) {
            $state = $this->resolver->resolve($group['alert'], $group['attempts'], $now);

            if ($state->bucket === $bucket) {
                $entries[] = $group + ['state' => $state];
            }
        }

        return $entries;
    }

    private function sentTimestamp(array $entry): int
    {
        return $entry['state']->primary?->sent_at?->getTimestamp() ?? 0;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function present(array $entries, Vet $vet, string $timezone, string $locale, bool $problem): array
    {
        if ($entries === []) {
            return [];
        }

        $profiles = $this->repo->profilesByIds(array_values(array_unique(array_column($entries, 'profile_id'))));
        $programs = $this->repo->programsByIds(
            array_values(array_unique(array_map(fn (array $e) => (int) $e['alert']->subject_id, $entries))),
            $vet->id,
        );

        $items = [];

        foreach ($entries as $entry) {
            $alert = $entry['alert'];
            /** @var AlertLineState $state */
            $state = $entry['state'];
            $profile = $profiles->get($entry['profile_id']);
            $program = $programs->get((int) $alert->subject_id);

            if ($profile === null || $program === null) {
                continue;
            }

            $payload = $alert->payload ?? [];
            $sentAt = $state->primary?->sent_at;

            $item = [
                'key'                => $alert->guid . ':' . $profile->guid,
                'type'               => $alert->type->value,
                'state'              => $state->state,
                'recipient'          => [
                    'profile_guid' => $profile->guid,
                    'name'         => $profile->user?->name,
                    'role'         => $profile->role?->name,
                ],
                'task_description'   => $payload['taskDescription'] ?? null,
                'scheduled_at_local' => LocalDateTime::from(CarbonImmutable::instance($alert->scheduled_at)->setTimezone($timezone), $locale)->toArray(),
                'sent_at'            => $sentAt?->toISOString(),
                'sent_at_local'      => $sentAt === null
                    ? null
                    : LocalDateTime::from(CarbonImmutable::instance($sentAt)->setTimezone($timezone), $locale)->toArray(),
                'destination_masked' => $state->primary?->destination_hint,
                'program'            => $this->programSummary($program),
            ];

            if ($problem) {
                $item['failure_code'] = $state->failureCode?->value ?? $state->primary?->failure_code?->value ?? 'unknown';
            }

            $items[] = $item;
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function programSummary(Program $program): array
    {
        return [
            'guid'          => $program->guid,
            'client'        => ['guid' => $program->client->guid, 'name' => $program->client->name],
            'establishment' => ['guid' => $program->establishment->guid, 'name' => $program->establishment->name],
            'technique'     => ['guid' => $program->technique->guid, 'name' => $program->technique->name],
            'protocol'      => ['guid' => $program->protocol->guid, 'name' => $program->protocol->name],
        ];
    }
}
