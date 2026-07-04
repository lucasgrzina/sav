<?php

namespace App\Services;

use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Contracts\Repositories\VetRepositoryInterface;
use App\Criteria\Shared\DateRangeCriteria;
use App\Criteria\Users\UserRoleCriteria;
use App\Criteria\Users\UserSearchCriteria;
use App\Criteria\Users\UserStatusCriteria;
use App\Criteria\Users\UserTypeCriteria;
use App\Criteria\Users\UserVetCriteria;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        private UserRepositoryInterface        $userRepository,
        private RoleRepositoryInterface        $roleRepository,
        private UserProfileRepositoryInterface $userProfileRepository,
        private VetRepositoryInterface         $vetRepository,
        private ClientRepositoryInterface      $clientRepository,
    ) {}

    public function list(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $type = $filters['type'] ?? null;

        return $this->userRepository->list(
            $perPage,
            new UserSearchCriteria($filters['search'] ?? null),
            new UserStatusCriteria($filters['status'] ?? null),
            new DateRangeCriteria($filters['date_from'] ?? null, $filters['date_to'] ?? null),
            new UserVetCriteria($filters['vet_guid'] ?? null),
            new UserTypeCriteria($type),
            new UserRoleCriteria($filters['role_guid'] ?? null, $type),
        );
    }

    public function findByGuid(string $guid): ?User
    {
        return $this->userRepository->findByGuid($guid);
    }

    public function create(array $data): User
    {
        $user = $this->userRepository->create([
            'first_name'        => $data['first_name'],
            'last_name'         => $data['last_name'],
            'name'              => $data['first_name'] . ' ' . $data['last_name'],
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'email_verified_at' => now(),
        ]);

        return $this->syncRoles($user, $data['role_guids'] ?? []);
    }

    public function update(User $user, array $data): User
    {
        $user = $this->userRepository->update($user, [
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'name'       => $data['first_name'] . ' ' . $data['last_name'],
            'email'      => $data['email'],
        ]);

        if (array_key_exists('role_guids', $data)) {
            return $this->syncRoles($user, $data['role_guids'] ?? []);
        }

        return $user->load('roles');
    }

    public function destroy(User $user): void
    {
        $this->userRepository->destroy($user);
    }

    public function toggleLock(User $user): User
    {
        return $this->userRepository->toggleLock($user);
    }

    public function changePassword(User $user, string $password): void
    {
        $this->userRepository->updatePassword($user, $password);
    }

    public function resetPassword(User $user): string
    {
        $randomPassword = $this->generateRandomPassword();
        $this->userRepository->updatePassword($user, $randomPassword);

        return $randomPassword;
    }

    public function syncRoles(User $user, array $roleGuids): User
    {
        $roles = $this->roleRepository->findManyByGuids($roleGuids);
        $user->syncRoles($roles);

        return $user->load('roles');
    }

    /**
     * Crea un usuario con N perfiles de tenant en una sola transacción.
     * No envía emails. El usuario queda verificado (email_verified_at = now()).
     *
     * @param  array $data  Datos validados de CreateTenantUserRequest
     * @return User         Usuario creado con relación 'roles' cargada (vacía, sin roles Spatie)
     * @throws \RuntimeException  Si un rol no es de tipo tenant,
     *                            si falta el tenant correspondiente al tipo de rol,
     *                            o si el tenant no existe.
     */
    public function createTenantUser(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'first_name'        => $data['first_name'],
                'last_name'         => $data['last_name'],
                'name'              => $data['first_name'] . ' ' . $data['last_name'],
                'email'             => $data['email'],
                'password'          => Hash::make($data['password']),
                'email_verified_at' => now(),
            ]);

            foreach ($data['profiles'] as $index => $profileData) {
                $role = $this->roleRepository->findByGuid($profileData['role_guid']);

                if (! $role) {
                    throw new \RuntimeException("Perfil #{$index}: rol no encontrado.");
                }

                if (! $role->isTenant()) {
                    throw new \RuntimeException("Perfil #{$index}: el rol '{$role->name}' no es un rol de tenant.");
                }

                $isTenantVet    = str_starts_with($role->name, 'vet');
                $isTenantClient = str_starts_with($role->name, 'client');

                if ($isTenantVet) {
                    if (empty($profileData['vet_guid'])) {
                        throw new \RuntimeException("Perfil #{$index}: el rol '{$role->name}' requiere seleccionar una veterinaria.");
                    }
                    $tenant = $this->vetRepository->findByGuid($profileData['vet_guid']);
                    if (! $tenant) {
                        throw new \RuntimeException("Perfil #{$index}: veterinaria no encontrada.");
                    }
                    $authenticatableType = 'vet';
                } elseif ($isTenantClient) {
                    if (empty($profileData['client_guid'])) {
                        throw new \RuntimeException("Perfil #{$index}: el rol '{$role->name}' requiere seleccionar un cliente.");
                    }
                    $tenant = $this->clientRepository->findByGuid($profileData['client_guid']);
                    if (! $tenant) {
                        throw new \RuntimeException("Perfil #{$index}: cliente no encontrado.");
                    }
                    $authenticatableType = 'client';
                } else {
                    throw new \RuntimeException("Perfil #{$index}: no se puede determinar el tipo de tenant para el rol '{$role->name}'.");
                }

                $this->userProfileRepository->create([
                    'user_id'              => $user->id,
                    'authenticatable_type' => $authenticatableType,
                    'authenticatable_id'   => $tenant->id,
                    'role_id'              => $role->id,
                ]);
            }

            return $user->load('roles');
        });
    }

    /**
     * Agrega un perfil de tenant a un usuario existente.
     *
     * @throws \RuntimeException
     */
    public function addProfile(User $user, array $profileData): User
    {
        $role = $this->roleRepository->findByGuid($profileData['role_guid']);

        if (! $role || ! $role->isTenant()) {
            throw new \RuntimeException('El rol seleccionado no es un rol de tenant válido.');
        }

        $isTenantVet    = str_starts_with($role->name, 'vet');
        $isTenantClient = str_starts_with($role->name, 'client');

        if ($isTenantVet) {
            if (empty($profileData['vet_guid'])) {
                throw new \RuntimeException("El rol '{$role->name}' requiere seleccionar una veterinaria.");
            }
            $tenant = $this->vetRepository->findByGuid($profileData['vet_guid']);
            if (! $tenant) {
                throw new \RuntimeException('Veterinaria no encontrada.');
            }
            $authenticatableType = 'vet';
            $authenticatableId   = $tenant->id;
        } elseif ($isTenantClient) {
            if (empty($profileData['client_guid'])) {
                throw new \RuntimeException("El rol '{$role->name}' requiere seleccionar un cliente.");
            }
            $tenant = $this->clientRepository->findByGuid($profileData['client_guid']);
            if (! $tenant) {
                throw new \RuntimeException('Cliente no encontrado.');
            }
            $authenticatableType = 'client';
            $authenticatableId   = $tenant->id;
        } else {
            throw new \RuntimeException("No se puede determinar el tipo de tenant para el rol '{$role->name}'.");
        }

        $duplicate = $user->profiles()
            ->where('authenticatable_type', $authenticatableType)
            ->where('authenticatable_id', $authenticatableId)
            ->exists();

        if ($duplicate) {
            $tenantLabel = $isTenantVet ? 'veterinaria' : 'cliente';
            throw new \RuntimeException("El usuario ya tiene un perfil en esa {$tenantLabel}.");
        }

        $this->userProfileRepository->create([
            'user_id'              => $user->id,
            'authenticatable_type' => $authenticatableType,
            'authenticatable_id'   => $authenticatableId,
            'role_id'              => $role->id,
        ]);

        $user->load(['roles', 'profiles.role', 'profiles.authenticatable']);

        return $user;
    }

    public function changeProfileRole(User $user, string $profileGuid, string $roleGuid): User
    {
        $profile = $this->userProfileRepository->findByGuid($profileGuid);

        if (!$profile || $profile->user_id !== $user->id) {
            throw new \RuntimeException('Perfil no encontrado.');
        }

        $role = $this->roleRepository->findByGuid($roleGuid);

        if (!$role || !$role->isTenant()) {
            throw new \RuntimeException('El rol seleccionado no es válido para un perfil de tenant.');
        }

        $this->userProfileRepository->update($profile, ['role_id' => $role->id]);

        $user->load(['roles', 'profiles.role', 'profiles.authenticatable']);

        return $user;
    }

    private function generateRandomPassword(): string
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $digits    = '0123456789';
        $symbols   = '!@#$%&';

        $password  = $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];

        $pool = $uppercase . $lowercase . $digits . $symbols;
        for ($i = 3; $i < 10; $i++) {
            $password .= $pool[random_int(0, strlen($pool) - 1)];
        }

        return str_shuffle($password);
    }
}
