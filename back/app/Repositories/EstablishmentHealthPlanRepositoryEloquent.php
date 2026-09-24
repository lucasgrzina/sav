<?php

namespace App\Repositories;

use App\Contracts\Repositories\EstablishmentHealthPlanRepositoryInterface;
use App\Models\EstablishmentHealthPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EstablishmentHealthPlanRepositoryEloquent extends BaseRepositoryEloquent implements EstablishmentHealthPlanRepositoryInterface
{
    protected function model(): string
    {
        return EstablishmentHealthPlan::class;
    }

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()
            ->where('vet_id', $vetId)
            ->with([
                'client:id,guid,name',
                'establishment:id,guid,name',
                'template:id,guid,name',
            ])
            ->withCount('activities')
            ->withCount(['activities as pending_count' => fn ($q) => $q->whereNull('confirmed_at')]);

        if (!empty($filters['client_guid'])) {
            $query->whereHas('client', fn ($q) => $q->where('guid', $filters['client_guid']));
        }

        if (!empty($filters['establishment_guid'])) {
            $query->whereHas('establishment', fn ($q) => $q->where('guid', $filters['establishment_guid']));
        }

        if (array_key_exists('cancelled', $filters) && $filters['cancelled'] !== null) {
            $filters['cancelled']
                ? $query->whereNotNull('cancelled_at')
                : $query->whereNull('cancelled_at');
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('establishment', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function findByGuid(string $guid): ?EstablishmentHealthPlan
    {
        /** @var EstablishmentHealthPlan|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByGuidForVet(string $guid, int $vetId): ?EstablishmentHealthPlan
    {
        /** @var EstablishmentHealthPlan|null */
        return $this->newQuery()
            ->with(['client', 'establishment', 'template.category', 'activities.activity', 'activities.confirmedBy.user'])
            ->where('guid', $guid)
            ->where('vet_id', $vetId)
            ->first();
    }

    public function existsActiveFor(int $establishmentId, int $templateId, int $year, ?int $excludeId = null, bool $lockForUpdate = false): bool
    {
        $query = $this->newQuery()
            ->where('establishment_id', $establishmentId)
            ->where('health_plan_template_id', $templateId)
            ->where('year', $year)
            ->whereNull('cancelled_at');

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    public function create(array $data): EstablishmentHealthPlan
    {
        /** @var EstablishmentHealthPlan */
        return parent::create($data);
    }
}
