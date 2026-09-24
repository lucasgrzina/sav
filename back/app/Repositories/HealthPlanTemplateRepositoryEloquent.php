<?php

namespace App\Repositories;

use App\Contracts\Repositories\HealthPlanTemplateRepositoryInterface;
use App\Models\EstablishmentHealthPlan;
use App\Models\HealthPlanTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class HealthPlanTemplateRepositoryEloquent extends BaseRepositoryEloquent
    implements HealthPlanTemplateRepositoryInterface
{
    protected function model(): string { return HealthPlanTemplate::class; }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()
            ->whereNull('vet_id')
            ->with('category')
            ->withCount('activities');

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['health_plan_category_guid'])) {
            $query->whereHas('category', function ($q) use ($filters) {
                $q->where('guid', $filters['health_plan_category_guid']);
            });
        }
        return $query->orderBy('name')->paginate($perPage);
    }

    public function paginateForVetScope(int $vetId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()
            ->selectRaw('health_plan_templates.*, CASE WHEN vet_id = ? THEN 1 ELSE 0 END as is_own', [$vetId])
            ->withExists(['establishmentHealthPlans as is_locked'])
            ->with(['category', 'vet:id,guid'])
            ->withCount('activities');

        match ($filters['scope'] ?? 'all') {
            'own'    => $query->where('vet_id', $vetId),
            'global' => $query->whereNull('vet_id'),
            default  => $query->where(fn ($q) => $q->where('vet_id', $vetId)->orWhereNull('vet_id')),
        };

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['health_plan_category_guid'])) {
            $query->whereHas('category', function ($q) use ($filters) {
                $q->where('guid', $filters['health_plan_category_guid']);
            });
        }

        return $query->orderByDesc('is_own')->orderBy('name')->paginate($perPage);
    }

    public function findByGuidForVetScope(string $guid, int $vetId): ?HealthPlanTemplate
    {
        /** @var HealthPlanTemplate|null */
        return $this->newQuery()
            ->selectRaw('health_plan_templates.*, CASE WHEN vet_id = ? THEN 1 ELSE 0 END as is_own', [$vetId])
            ->withExists(['establishmentHealthPlans as is_locked'])
            ->with(['category', 'activities', 'vet:id,guid'])
            ->where('guid', $guid)
            ->where(fn ($q) => $q->where('vet_id', $vetId)->orWhereNull('vet_id'))
            ->first();
    }

    public function findOwnByGuidForVet(string $guid, int $vetId): ?HealthPlanTemplate
    {
        /** @var HealthPlanTemplate|null */
        return $this->newQuery()
            ->withExists(['establishmentHealthPlans as is_locked'])
            ->with(['category', 'activities'])
            ->where('guid', $guid)
            ->where('vet_id', $vetId)
            ->first();
    }

    public function hasInstantiatedPlans(int $templateId): bool
    {
        return EstablishmentHealthPlan::where('health_plan_template_id', $templateId)->exists();
    }

    /**
     * Uso EXCLUSIVO del panel admin (super-admin): solo alcanza plantillas globales
     * (vet_id IS NULL). Nunca debe devolver una plantilla propia de un vet — el panel
     * tenant tiene su propio par de métodos scopeados (findByGuidForVetScope /
     * findOwnByGuidForVet). Si el guid pertenece a un vet, se comporta como no encontrada.
     */
    public function findByGuid(string $guid): ?HealthPlanTemplate
    {
        /** @var HealthPlanTemplate|null */
        return $this->newQuery()
            ->whereNull('vet_id')
            ->with(['category', 'activities'])
            ->where('guid', $guid)
            ->first();
    }

    public function create(array $data): HealthPlanTemplate
    {
        /** @var HealthPlanTemplate */
        return parent::create($data);
    }

    /**
     * Sincroniza el pivot health_plan_template_activity.
     *
     * @param  array  $activityData  Formato: [activity_id => ['months' => '[1,3,6]']]
     *                               months ya viene como JSON string para guardar en BD.
     */
    public function syncActivities(HealthPlanTemplate $template, array $activityData): void
    {
        $template->activities()->sync($activityData);
    }
}
