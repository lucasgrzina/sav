<?php

namespace App\Contracts\Repositories;

use App\Models\Client;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

interface UserProfileRepositoryInterface
{
    public function findByGuid(string $guid): ?UserProfile;
    public function findForUserAndVet(User $user, Vet $vet): ?UserProfile;
    public function create(array $data): UserProfile;
    public function listForVet(Vet $vet): Collection;
    public function update(Model $profile, array $data): UserProfile;
    public function destroy(Model $profile): bool|null;

    /**
     * Lista todos los UserProfiles de un Client (todos los roles client-*).
     */
    public function listForClient(Client $client): Collection;

    /**
     * Lista los UserProfiles con role 'client-owner' de un Client dado.
     */
    public function listOwnersForClient(Client $client): Collection;

    /**
     * Busca un UserProfile de tipo 'client' para un User y Client específicos.
     * Retorna null si no existe.
     */
    public function findForUserAndClient(User $user, Client $client): ?UserProfile;

    /**
     * Lista todos los UserProfiles de un User dado con sus relaciones de rol y tenant.
     */
    public function listForUser(User $user): Collection;

    public function toggleBlock(UserProfile $profile): UserProfile;
}
