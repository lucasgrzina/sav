<?php

namespace App\Repositories;

use App\Contracts\Repositories\HealthPlanTemplateRepositoryInterface;
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

    public function findByGuid(string $guid): ?HealthPlanTemplate
    {
        /** @var HealthPlanTemplate|null */
        return $this->newQuery()
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
