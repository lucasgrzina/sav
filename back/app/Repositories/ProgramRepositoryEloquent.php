<?php

namespace App\Repositories;

use App\Contracts\Repositories\ProgramRepositoryInterface;
use App\Models\Program;
use App\Models\Technique;
use App\Models\UserProfile;
use App\Notifications\Enums\AlertType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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

    public function unlinkedClientManagerIds(int $establishmentId, array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        return UserProfile::query()
            ->whereIn('id', $profileIds)
            ->where('authenticatable_type', 'client')
            ->whereDoesntHave('establishments', fn ($q) => $q->where('establishments.id', $establishmentId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function detachManagersFromActivePrograms(int $establishmentId, array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $affected = [];

        Program::query()
            ->where('establishment_id', $establishmentId)
            ->whereNull('cancelled_at')
            ->whereHas('managers', fn ($q) => $q->whereIn('user_profiles.id', $profileIds))
            ->get()
            ->each(function (Program $program) use ($profileIds, &$affected) {
                $program->managers()->detach($profileIds);
                $affected[] = $program->id;
            });

        return $affected;
    }

    public function findActiveProgramsWithUnlinkedClientManagers(): array
    {
        $rows = DB::table('program_manager as pm')
            ->join('programs as p', 'p.id', '=', 'pm.program_id')
            ->join('user_profiles as up', 'up.id', '=', 'pm.user_profile_id')
            ->whereNull('p.cancelled_at')
            ->where('up.authenticatable_type', 'client')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('establishment_user_profile as eup')
                ->whereColumn('eup.establishment_id', 'p.establishment_id')
                ->whereColumn('eup.user_profile_id', 'up.id'))
            ->select('pm.program_id', 'p.establishment_id', 'up.id as profile_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->program_id]['establishment_id'] = (int) $row->establishment_id;
            $result[(int) $row->program_id]['profile_ids'][]    = (int) $row->profile_id;
        }

        return $result;
    }

    public function findActiveProgramsWithStaleAlertRecipients(): array
    {
        $rows = DB::table('alert_recipients as ar')
            ->join('alerts as a', 'a.id', '=', 'ar.alert_id')
            ->join('programs as p', 'p.id', '=', 'a.subject_id')
            ->where('a.subject_type', 'program')
            ->where('a.type', AlertType::ProgramTaskDue->value)
            ->where('a.status', 'pending')
            ->whereNull('p.cancelled_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('program_manager as pm')
                ->whereColumn('pm.program_id', 'p.id')
                ->whereColumn('pm.user_profile_id', 'ar.user_profile_id'))
            ->select('p.id as program_id', 'p.establishment_id', 'ar.user_profile_id as profile_id')
            ->distinct()
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->program_id]['establishment_id'] = (int) $row->establishment_id;
            $result[(int) $row->program_id]['profile_ids'][]    = (int) $row->profile_id;
        }

        return $result;
    }

    public function detachManagers(int $programId, array $profileIds): void
    {
        Program::query()->whereKey($programId)->first()?->managers()->detach($profileIds);
    }

    public function findForAlertRegeneration(int $programId): ?Program
    {
        return Program::query()
            ->with('targets', 'protocol.tasks.alerts', 'managers.role')
            ->find($programId);
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
