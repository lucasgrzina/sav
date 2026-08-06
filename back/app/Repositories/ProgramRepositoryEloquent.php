<?php

namespace App\Repositories;

use App\Contracts\Repositories\ProgramRepositoryInterface;
use App\Models\Program;
use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProgramRepositoryEloquent extends BaseRepositoryEloquent implements ProgramRepositoryInterface
{
    protected function model(): string
    {
        return Program::class;
    }

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()
            ->where('vet_id', $vetId)
            ->with([
                'client:id,guid,name',
                'establishment:id,guid,name',
                'technique:id,guid,name',
                'protocol:id,guid,name',
                'targets' => fn ($q) => $q->orderBy('target_date'),
            ])
            ->withCount('targets');

        if (!empty($filters['technique_guid'])) {
            $technique = Technique::where('guid', $filters['technique_guid'])->first();
            if ($technique) {
                $techniqueIds = $technique->children()->pluck('id')->push($technique->id);
                $query->whereIn('technique_id', $techniqueIds);
            }
        }

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
            $query->where(fn ($q) => $q
                ->where('comments', 'like', "%{$search}%")
                ->orWhereHas('establishment', fn ($q2) => $q2->where('name', 'like', "%{$search}%")));
        }

        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        foreach ($paginator->items() as $program) {
            $program->next_target_date = $this->resolveNextTargetDate($program);
        }

        return $paginator;
    }

    public function findByGuidForVet(string $guid, int $vetId): ?Program
    {
        /** @var Program|null */
        return $this->newQuery()
            ->with([
                'targets.animals',
                'managers.user',
                'managers.role',
                'client',
                'establishment',
                'technique',
                'protocol.tasks.alerts',
            ])
            ->where('guid', $guid)
            ->where('vet_id', $vetId)
            ->first();
    }

    public function create(array $data): Program
    {
        /** @var Program */
        return parent::create($data);
    }

    /**
     * Calcula el target_date más próximo no vencido (DEC-09). Si todos los targets ya
     * vencieron, retorna el máximo (más próximo en general, aunque vencido).
     */
    private function resolveNextTargetDate(Program $program): ?string
    {
        $targets = $program->targets;
        if ($targets->isEmpty()) {
            return null;
        }

        $today = now()->startOfDay();

        $upcoming = $targets->first(fn ($target) => $target->target_date->greaterThanOrEqualTo($today));
        if ($upcoming) {
            return $upcoming->target_date->toDateString();
        }

        return $targets->max('target_date')->toDateString();
    }
}
