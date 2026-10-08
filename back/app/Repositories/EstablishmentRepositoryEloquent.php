<?php

namespace App\Repositories;

use App\Contracts\Repositories\EstablishmentRepositoryInterface;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class EstablishmentRepositoryEloquent extends BaseRepositoryEloquent implements EstablishmentRepositoryInterface
{
    protected function model(): string
    {
        return Establishment::class;
    }

    public function findByGuidForClient(string $guid, Client $client): ?Establishment
    {
        return $client->establishments()
            ->where('guid', $guid)
            ->first();
    }

    public function listForClient(Client $client, bool $withStaff = true): Collection
    {
        $query = $client->establishments()->withCount('staff')->latest();

        if ($withStaff) {
            $query->with(['staff.user', 'staff.role']);
        }

        return $query->get();
    }

    public function create(array $data): Establishment
    {
        /** @var Establishment $establishment */
        $establishment = $this->model->newQuery()->create($data);
        return $establishment;
    }

    public function update(Model $establishment, array $data): Establishment
    {
        $establishment->fill($data);
        $establishment->save();
        /** @var Establishment $establishment */
        return $establishment;
    }

    public function destroy(Model $establishment): bool|null
    {
        return $establishment->delete();
    }

    public function syncStaff(Establishment $establishment, array $profileIds): array
    {
        return $establishment->staff()->sync($profileIds);
    }

    public function hasStaff(Establishment $establishment, UserProfile $profile): bool
    {
        return $establishment->staff()->whereKey($profile->id)->exists();
    }

    public function listActiveStaff(Establishment $establishment): Collection
    {
        return $establishment->staff()
            ->whereNull('user_profiles.blocked_at')
            ->with(['user', 'role'])
            ->get();
    }
}
