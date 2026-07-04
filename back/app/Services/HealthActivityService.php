<?php

namespace App\Services;

use App\Contracts\Repositories\HealthActivityRepositoryInterface;
use App\Models\HealthActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class HealthActivityService
{
    public function __construct(
        private HealthActivityRepositoryInterface $repo,
    ) {}

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repo->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->repo->listAll();
    }

    public function findByGuid(string $guid): ?HealthActivity
    {
        return $this->repo->findByGuid($guid);
    }

    public function create(array $data): HealthActivity
    {
        return $this->repo->create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    public function update(HealthActivity $activity, array $data): HealthActivity
    {
        /** @var HealthActivity */
        return $this->repo->update($activity, [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * @throws \RuntimeException si la actividad está en uso en algún template
     */
    public function destroy(HealthActivity $activity): void
    {
        if ($this->repo->isUsedInTemplates($activity)) {
            throw new \RuntimeException(
                'La actividad sanitaria está en uso en una o más plantillas y no puede eliminarse.'
            );
        }
        $this->repo->destroy($activity);
    }
}
