<?php

namespace App\Contracts\Repositories;

use App\Models\HealthPlanTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface HealthPlanTemplateRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuid(string $guid): ?HealthPlanTemplate;
    public function create(array $data): HealthPlanTemplate;
    public function update(Model $model, array $data): Model;
    public function destroy(Model $model): bool|null;
    // Sincroniza el pivot. $activityData = [activity_id => ['months' => '[1,3]']]
    public function syncActivities(HealthPlanTemplate $template, array $activityData): void;

    public function paginateForVetScope(int $vetId, array $filters, int $perPage): LengthAwarePaginator;
    public function findByGuidForVetScope(string $guid, int $vetId): ?HealthPlanTemplate;
    public function findOwnByGuidForVet(string $guid, int $vetId): ?HealthPlanTemplate;
    public function hasInstantiatedPlans(int $templateId): bool;
}
