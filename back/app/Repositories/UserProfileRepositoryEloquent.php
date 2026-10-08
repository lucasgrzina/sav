<?php

namespace App\Repositories;

use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Models\Client;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class UserProfileRepositoryEloquent extends BaseRepositoryEloquent implements UserProfileRepositoryInterface
{
    protected function model(): string
    {
        return UserProfile::class;
    }

    public function findByGuid(string $guid): ?UserProfile
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findForUserAndVet(User $user, Vet $vet): ?UserProfile
    {
        return $this->newQuery()
            ->where('user_id', $user->id)
            ->where('authenticatable_type', 'vet')
            ->where('authenticatable_id', $vet->id)
            ->first();
    }

    public function create(array $data): UserProfile
    {
        return $this->model->newQuery()->create($data);
    }

    public function listForVet(Vet $vet): Collection
    {
        return $this->newQuery()
            ->with(['user', 'role'])
            ->where('authenticatable_type', 'vet')
            ->where('authenticatable_id', $vet->id)
            ->get();
    }

    public function update(Model $profile, array $data): UserProfile
    {
        $profile->fill($data);
        $profile->save();
        /** @var UserProfile $profile */
        return $profile;
    }

    public function destroy(Model $profile): bool|null
    {
        return $profile->delete();
    }

    public function findManyByGuidsForClient(array $guids, Client $client): Collection
    {
        return $this->newQuery()
            ->with('role')
            ->where('authenticatable_type', 'client')
            ->where('authenticatable_id', $client->id)
            ->whereIn('guid', $guids)
            ->get();
    }

    public function listForClient(Client $client): Collection
    {
        return $this->newQuery()
            ->with(['user', 'role', 'establishments'])
            ->where('authenticatable_type', 'client')
            ->where('authenticatable_id', $client->id)
            ->get();
    }

    public function findForUserAndClient(User $user, Client $client): ?UserProfile
    {
        return $this->newQuery()
            ->where('user_id', $user->id)
            ->where('authenticatable_type', 'client')
            ->where('authenticatable_id', $client->id)
            ->first();
    }

    public function listForUser(User $user): Collection
    {
        return $this->newQuery()
            ->with(['role', 'authenticatable'])
            ->where('user_id', $user->id)
            ->get();
    }

    public function toggleBlock(UserProfile $profile): UserProfile
    {
        $profile->blocked_at = $profile->blocked_at ? null : now();
        $profile->save();
        return $profile;
    }

    public function listByRoleForVet(Vet $vet, string $roleName): Collection
    {
        return $this->newQuery()
            ->with(['user', 'role'])
            ->whereHas('role', fn ($q) => $q->where('name', $roleName))
            ->where('authenticatable_type', 'vet')
            ->where('authenticatable_id', $vet->id)
            ->get();
    }
}
