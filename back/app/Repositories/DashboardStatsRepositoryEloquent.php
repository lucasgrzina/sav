<?php

namespace App\Repositories;

use App\Contracts\Repositories\DashboardStatsRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class DashboardStatsRepositoryEloquent implements DashboardStatsRepositoryInterface
{
    private const SENT_STATUSES = ['sent', 'delivered', 'read'];

    public function vetCounts(): array
    {
        $row = DB::table('vets')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN validated_at IS NOT NULL AND suspended_at IS NULL THEN 1 ELSE 0 END) as active')
            ->selectRaw('SUM(CASE WHEN suspended_at IS NOT NULL THEN 1 ELSE 0 END) as suspended')
            ->first();

        return [
            'active'    => (int) ($row->active ?? 0),
            'suspended' => (int) ($row->suspended ?? 0),
            'total'     => (int) ($row->total ?? 0),
        ];
    }

    public function pendingVets(): int
    {
        return DB::table('vets')
            ->whereNull('validated_at')
            ->whereNull('suspended_at')
            ->count();
    }

    public function platformTotals(CarbonInterface $today): array
    {
        $activePrograms = DB::table('programs')
            ->whereNull('cancelled_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('program_targets')
                ->whereColumn('program_targets.program_id', 'programs.id')
                ->where('program_targets.target_date', '>=', $today->toDateString()))
            ->count();

        return [
            'clients'         => DB::table('clients')->count(),
            'establishments'  => DB::table('establishments')->count(),
            'animals'         => DB::table('animals')->where('type', 'livestock')->count(),
            'active_programs' => $activePrograms,
        ];
    }

    public function sentPerDay(CarbonInterface $from, CarbonInterface $to): array
    {
        return DB::table('alert_recipients')
            ->selectRaw('DATE(sent_at) as d, COUNT(*) as n')
            ->whereIn('status', self::SENT_STATUSES)
            ->whereBetween('sent_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->groupBy('d')
            ->pluck('n', 'd')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function deliveryByChannel(CarbonInterface $from): array
    {
        $rows = DB::table('alert_recipients')
            ->select('channel', 'status', DB::raw('COUNT(*) as total'))
            ->whereIn('status', ['sent', 'delivered', 'read', 'failed'])
            ->where(fn ($q) => $q->where('sent_at', '>=', $from->toDateTimeString())
                ->orWhere(fn ($q) => $q->whereNull('sent_at')
                    ->where('updated_at', '>=', $from->toDateTimeString())))
            ->groupBy('channel', 'status')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->channel] ??= ['ok' => 0, 'failed' => 0, 'sent' => 0];

            $key = match ($row->status) {
                'delivered', 'read' => 'ok',
                'failed'            => 'failed',
                default             => 'sent',
            };
            $result[$row->channel][$key] += (int) $row->total;
        }

        return $result;
    }
}
