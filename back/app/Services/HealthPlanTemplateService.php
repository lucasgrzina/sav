<?php

namespace App\Services;

use App\Contracts\Repositories\HealthActivityRepositoryInterface;
use App\Contracts\Repositories\HealthPlanCategoryRepositoryInterface;
use App\Contracts\Repositories\HealthPlanTemplateRepositoryInterface;
use App\Models\HealthPlanTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class HealthPlanTemplateService
{
    public function __construct(
        private HealthPlanTemplateRepositoryInterface  $templateRepo,
        private HealthPlanCategoryRepositoryInterface  $categoryRepo,
        private HealthActivityRepositoryInterface      $activityRepo,
    ) {}

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->templateRepo->paginate($filters, $perPage);
    }

    public function findByGuid(string $guid): ?HealthPlanTemplate
    {
        return $this->templateRepo->findByGuid($guid);
    }

    /**
     * Crea un template con sus actividades asignadas.
     *
     * @param  array  $data  Validated. Incluye 'health_plan_category_guid' y 'activities'.
     * @throws \RuntimeException si la categoría no existe
     */
    public function create(array $data): HealthPlanTemplate
    {
        return DB::transaction(function () use ($data) {
            $category = $this->categoryRepo->findByGuid($data['health_plan_category_guid']);
            if (!$category) {
                throw new \RuntimeException('Categoría no encontrada.');
            }

            $template = $this->templateRepo->create([
                'name'                    => $data['name'],
                'health_plan_category_id' => $category->id,
            ]);

            $syncData = $this->buildSyncData($data['activities'] ?? []);
            $this->templateRepo->syncActivities($template, $syncData);

            return $template->load(['category', 'activities']);
        });
    }

    /**
     * Actualiza un template y resincroniza sus actividades.
     *
     * @throws \RuntimeException si la categoría no existe
     */
    public function update(HealthPlanTemplate $template, array $data): HealthPlanTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $category = $this->categoryRepo->findByGuid($data['health_plan_category_guid']);
            if (!$category) {
                throw new \RuntimeException('Categoría no encontrada.');
            }

            $this->templateRepo->update($template, [
                'name'                    => $data['name'],
                'health_plan_category_id' => $category->id,
            ]);

            $syncData = $this->buildSyncData($data['activities'] ?? []);
            $this->templateRepo->syncActivities($template, $syncData);

            return $template->fresh()->load(['category', 'activities']);
        });
    }

    public function destroy(HealthPlanTemplate $template): void
    {
        // El cascade en la FK elimina automáticamente los registros del pivot
        $this->templateRepo->destroy($template);
    }

    /**
     * Convierte el array de actividades del payload al formato que espera sync().
     *
     * Input:  [{ health_activity_guid: 'uuid', months: [1,3,6,9] }, ...]
     * Output: [activity_id => ['months' => [1,3,6,9]], ...]
     *          ^ months como array PHP; el cast 'array' del modelo pivot lo serializa en save()
     *
     * Los GUIDs inválidos (no encontrados) se ignoran silenciosamente.
     * Si se quiere validar que todos los GUIDs existan, hacerlo en el FormRequest
     * con Rule::exists('health_activities', 'guid').
     */
    private function buildSyncData(array $activities): array
    {
        if (empty($activities)) {
            return [];
        }

        $guids    = array_column($activities, 'health_activity_guid');
        $guidToId = $this->activityRepo->resolveGuidsToIds($guids);

        $syncData   = [];
        $sortOrder  = 0;
        foreach ($activities as $assignment) {
            $guid = $assignment['health_activity_guid'];
            if (!isset($guidToId[$guid])) {
                continue;
            }
            $syncData[$guidToId[$guid]] = [
                'months'     => $assignment['months'],
                'sort_order' => $sortOrder++,
            ];
        }
        return $syncData;
    }
}
