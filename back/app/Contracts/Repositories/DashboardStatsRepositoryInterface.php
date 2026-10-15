<?php

namespace App\Contracts\Repositories;

use Carbon\CarbonInterface;

interface DashboardStatsRepositoryInterface
{
    /** @return array{active: int, suspended: int, total: int} */
    public function vetCounts(): array;

    /** Vets awaiting validation (validated_at null, not suspended). Always queried live. */
    public function pendingVets(): int;

    /** @return array{clients: int, establishments: int, animals: int, active_programs: int} */
    public function platformTotals(CarbonInterface $today): array;

    /**
     * Sent notifications (sent/delivered/read) grouped by UTC day.
     *
     * @return array<string, int> [Y-m-d => count]
     */
    public function sentPerDay(CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Delivery outcome per channel since $from. Pending and suppressed are excluded.
     *
     * @return array<string, array{ok: int, failed: int, sent: int}> [channel => counts]
     */
    public function deliveryByChannel(CarbonInterface $from): array;
}
