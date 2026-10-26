<?php

namespace App\Repositories;

use App\Contracts\Repositories\VetHomeRepositoryInterface;
use App\Models\Program;
use App\Models\ProgramTarget;
use App\Models\UserProfile;
use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Services\ProgramAlerts\ProgramAlertLineQuery;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VetHomeRepositoryEloquent implements VetHomeRepositoryInterface
{
    public function establishmentIdsForProfile(UserProfile $profile): array
    {
        return $profile->establishments()
            ->pluck('establishments.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function upcomingPrograms(int $vetId, string $today, ?array $establishmentIds, int $limit): Collection
    {
        $nextTarget = ProgramTarget::query()
            ->selectRaw('MIN(program_targets.target_date)')
            ->whereColumn('program_targets.program_id', 'programs.id')
            ->where('program_targets.target_date', '>=', $today);

        return Program::query()
            ->select('programs.*')
            ->selectSub($nextTarget, 'next_target_date')
            ->where('programs.vet_id', $vetId)
            ->whereNull('programs.cancelled_at')
            ->when($establishmentIds !== null, fn (Builder $q) => $q->whereIn('programs.establishment_id', $establishmentIds))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('program_targets')
                ->whereColumn('program_targets.program_id', 'programs.id')
                ->where('program_targets.target_date', '>=', $today))
            ->with([
                'client:id,guid,name',
                'establishment:id,guid,name',
                'technique:id,guid,name',
                'protocol:id,guid,name',
            ])
            ->orderBy('next_target_date')
            ->orderBy('programs.id')
            ->limit($limit)
            ->get();
    }

    public function recentReachedPairs(int $vetId, ?array $establishmentIds, int $limit): array
    {
        $rows = $this->pairsBase($vetId, $establishmentIds)
            ->whereIn('ar.status', [DeliveryStatus::Sent->value, DeliveryStatus::Delivered->value, DeliveryStatus::Read->value])
            ->whereNotNull('ar.sent_at')
            ->select('ar.alert_id', 'ar.user_profile_id')
            ->selectRaw('MAX(ar.sent_at) as last_sent_at')
            ->groupBy('ar.alert_id', 'ar.user_profile_id')
            ->orderByDesc('last_sent_at')
            ->limit($limit)
            ->get();

        return $this->toPairs($rows);
    }

    public function problemPairs(int $vetId, ?array $establishmentIds, CarbonInterface $since, CarbonInterface $until, int $limit): array
    {
        $rows = $this->pairsBase($vetId, $establishmentIds)
            ->whereIn('ar.status', [DeliveryStatus::Failed->value, DeliveryStatus::Suppressed->value])
            ->where('a.status', '!=', AlertStatus::Cancelled->value)
            ->whereNull('p.cancelled_at')
            ->where('a.scheduled_at', '>=', $since->toDateTimeString())
            ->where('a.scheduled_at', '<=', $until->toDateTimeString())
            ->select('ar.alert_id', 'ar.user_profile_id')
            ->selectRaw('MAX(a.scheduled_at) as last_scheduled_at')
            ->groupBy('ar.alert_id', 'ar.user_profile_id')
            ->orderByDesc('last_scheduled_at')
            ->limit($limit)
            ->get();

        return $this->toPairs($rows);
    }

    public function groupsForPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $wanted = [];
        foreach ($pairs as [$alertId, $profileId]) {
            $wanted[$alertId][$profileId] = true;
        }

        $alerts = Alert::query()
            ->whereIn('id', array_keys($wanted))
            ->with(['recipients' => fn ($query) => $query
                ->where('channel', '!=', Channel::Push->value)
                ->select([
                    'id', 'guid', 'alert_id', 'user_profile_id', 'channel', 'status', 'failure_code', 'destination_hint',
                    'attempts', 'sent_at', 'delivered_at', 'last_retry_at', 'created_at',
                ])
                ->orderBy('id')])
            ->get(['id', 'guid', 'type', 'payload', 'subject_type', 'subject_id', 'scheduled_at', 'status', 'cancelled_at', 'cancel_reason', 'vet_id'])
            ->keyBy('id');

        $groups = [];
        foreach ($pairs as [$alertId, $profileId]) {
            $alert = $alerts->get($alertId);
            if ($alert === null) {
                continue;
            }

            $attempts = $alert->recipients->where('user_profile_id', $profileId)->values();
            if ($attempts->isEmpty()) {
                continue;
            }

            $groups[] = ['alert' => $alert, 'profile_id' => $profileId, 'attempts' => $attempts];
        }

        return $groups;
    }

    public function profilesByIds(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return UserProfile::query()
            ->with(['user:id,name', 'role:id,name'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    public function programsByIds(array $ids, int $vetId): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Program::query()
            ->where('vet_id', $vetId)
            ->whereIn('id', $ids)
            ->with(['client:id,guid,name', 'establishment:id,guid,name', 'technique:id,guid,name', 'protocol:id,guid,name'])
            ->get()
            ->keyBy('id');
    }

    /**
     * Non-push attempts of program alerts of this vet. Tenant isolation: the alert AND the program
     * both belong to the vet (`subject_id` alone is an internal id shared across morph types).
     */
    private function pairsBase(int $vetId, ?array $establishmentIds): \Illuminate\Database\Query\Builder
    {
        return DB::table('alert_recipients as ar')
            ->join('alerts as a', 'a.id', '=', 'ar.alert_id')
            ->join('programs as p', 'p.id', '=', 'a.subject_id')
            ->where('a.vet_id', $vetId)
            ->where('p.vet_id', $vetId)
            ->where('a.subject_type', 'program')
            ->whereIn('a.type', array_map(fn (AlertType $t) => $t->value, ProgramAlertLineQuery::TYPES))
            ->where('ar.channel', '!=', Channel::Push->value)
            ->when($establishmentIds !== null, fn ($q) => $q->whereIn('p.establishment_id', $establishmentIds));
    }

    /** @return list<array{0: int, 1: int}> */
    private function toPairs(Collection $rows): array
    {
        return $rows->map(fn ($row) => [(int) $row->alert_id, (int) $row->user_profile_id])->values()->all();
    }
}
