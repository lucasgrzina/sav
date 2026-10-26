<?php

namespace App\Contracts\Repositories;

use App\Models\Program;
use App\Models\UserProfile;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read queries of the vet "Inicio" screen. Every method is scoped by vet; `$establishmentIds`
 * further restricts to those establishments (null = no restriction, [] = nothing visible).
 */
interface VetHomeRepositoryInterface
{
    /** Establishment ids a client-side profile is linked to. */
    public function establishmentIdsForProfile(UserProfile $profile): array;

    /**
     * Active programs with at least one target on or after `$today`, soonest first.
     * Each program carries a `next_target_date` attribute (Y-m-d).
     *
     * @param list<int>|null $establishmentIds
     * @return Collection<int, Program>
     */
    public function upcomingPrograms(int $vetId, string $today, ?array $establishmentIds, int $limit): Collection;

    /**
     * Most recent (alert, person) pairs that have a delivery attempt that reached the person.
     *
     * @param list<int>|null $establishmentIds
     * @return list<array{0: int, 1: int}> [alert_id, user_profile_id]
     */
    public function recentReachedPairs(int $vetId, ?array $establishmentIds, int $limit): array;

    /**
     * (alert, person) pairs with a failed or suppressed attempt on non-cancelled alerts of
     * active programs, scheduled between `$since` and `$until`, newest first.
     *
     * @param list<int>|null $establishmentIds
     * @return list<array{0: int, 1: int}> [alert_id, user_profile_id]
     */
    public function problemPairs(int $vetId, ?array $establishmentIds, CarbonInterface $since, CarbonInterface $until, int $limit): array;

    /**
     * Loads the alerts of the given pairs with every non-push attempt of each person.
     *
     * @param list<array{0: int, 1: int}> $pairs
     * @return list<array{alert: Alert, profile_id: int, attempts: Collection<int, AlertRecipient>}>
     */
    public function groupsForPairs(array $pairs): array;

    /**
     * @param list<int> $ids
     * @return Collection<int, UserProfile> keyed by id, with user and role
     */
    public function profilesByIds(array $ids): Collection;

    /**
     * @param list<int> $ids
     * @return Collection<int, Program> keyed by id, with client, establishment, technique and protocol
     */
    public function programsByIds(array $ids, int $vetId): Collection;
}
