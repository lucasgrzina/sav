<?php

namespace App\Services;

use App\Contracts\Repositories\DashboardStatsRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class DashboardStatsService
{
    public const CACHE_KEY = 'dashboard:admin';
    public const CACHE_TTL_SECONDS = 300;

    public function __construct(private DashboardStatsRepositoryInterface $repo) {}

    /**
     * Platform-wide stats. Everything is cached for 5 minutes except vets.pending,
     * which is actionable for admins and always read live.
     */
    public function forAdmin(): array
    {
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->build());

        $data['vets']['pending'] = $this->repo->pendingVets();

        return $data;
    }

    private function build(): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->startOfDay();
        $weekStart = $today->subDays(6);

        $vets = $this->repo->vetCounts();
        $perDay = $this->repo->sentPerDay($weekStart, $now->endOfDay());

        $sent = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->addDays($i)->toDateString();
            $sent[] = ['date' => $date, 'count' => $perDay[$date] ?? 0];
        }

        $channels = [];
        $totalOk = 0;
        $totalAttempts = 0;
        foreach ($this->repo->deliveryByChannel($today->subDays(30)) as $channel => $counts) {
            $attempts = $counts['ok'] + $counts['failed'] + $counts['sent'];
            $totalOk += $counts['ok'];
            $totalAttempts += $attempts;
            $channels[] = [
                'channel' => $channel,
                'ok'      => $counts['ok'],
                'failed'  => $counts['failed'],
                'rate'    => $this->rate($counts['ok'], $attempts),
            ];
        }

        return [
            'generated_at' => $now->toIso8601ZuluString(),
            'vets'         => [
                'active'    => $vets['active'],
                'pending'   => 0,
                'suspended' => $vets['suspended'],
                'total'     => $vets['total'],
            ],
            'totals'       => $this->repo->platformTotals($today),
            'sent_last_7d' => $sent,
            'delivery_30d' => [
                'rate'       => $this->rate($totalOk, $totalAttempts),
                'by_channel' => $channels,
            ],
        ];
    }

    private function rate(int $ok, int $attempts): ?float
    {
        return $attempts === 0 ? null : round($ok / $attempts * 100, 1);
    }
}
