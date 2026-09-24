<?php

namespace App\Contracts\Repositories;

use App\Models\EstablishmentHealthPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface EstablishmentHealthPlanRepositoryInterface
{
    public function findByGuid(string $guid): ?EstablishmentHealthPlan;

    public function findByGuidForVet(string $guid, int $vetId): ?EstablishmentHealthPlan;

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * @param bool $lockForUpdate Adquiere un lock de fila/rango (FOR UPDATE) sobre la
     * consulta — solo tiene efecto real dentro de una transacción abierta. Se usa en el
     * recheck de EstablishmentHealthPlanService::create() para cerrar la ventana de
     * condición de carrera bajo REPEATABLE READ (dos instanciaciones concurrentes del
     * mismo establecimiento+template+año). El índice `ehp_dedup_lookup_idx` cubre el
     * rango exacto que InnoDB bloquea.
     */
    public function existsActiveFor(int $establishmentId, int $templateId, int $year, ?int $excludeId = null, bool $lockForUpdate = false): bool;

    public function create(array $data): EstablishmentHealthPlan;

    /**
     * Firma con Model (no EstablishmentHealthPlan) para ser compatible con
     * BaseRepositoryEloquent::update(), igual que ProgramRepositoryInterface y
     * HealthPlanTemplateRepositoryInterface — el código real nunca estrecha este tipo.
     */
    public function update(Model $model, array $data): Model;
}
