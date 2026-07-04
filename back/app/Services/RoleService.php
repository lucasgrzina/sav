<?php

namespace App\Services;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Criteria\Roles\RoleTypeCriteria;
use App\Criteria\Shared\DateRangeCriteria;
use App\Criteria\Shared\SearchCriteria;
use App\Exceptions\RoleImmutableException;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RoleService
{
    public function __construct(
        private RoleRepositoryInterface $roleRepository,
        private PermissionRepositoryInterface $permissionRepository,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->roleRepository->list(
            $perPage,
            new SearchCriteria($filters['search'] ?? null),
            new DateRangeCriteria($filters['date_from'] ?? null, $filters['date_to'] ?? null),
            new RoleTypeCriteria($filters['type'] ?? null),
        );
    }

    public function findByGuid(string $guid): ?Role
    {
        return $this->roleRepository->findByGuid($guid);
    }

    public function create(array $data): Role
    {
        // Roles creados via API son siempre platform (DEC-07)
        $data['type'] = Role::TYPE_PLATFORM;

        $role = $this->roleRepository->create($data);

        if (! empty($data['permissions'])) {
            $permissions = $this->permissionRepository->findManyByGuids($data['permissions']);
            $role->syncPermissions($permissions);
        }

        return $role->load('permissions');
    }

    public function update(Role $role, array $data): Role
    {
        if ($role->isTenant()) {
            if (array_key_exists('name', $data) && $data['name'] !== $role->name) {
                throw new RoleImmutableException('El nombre de un rol de tenant no puede modificarse.');
            }
            unset($data['name']);
        }

        if (! empty($data['name'])) {
            $role = $this->roleRepository->update($role, $data);
        }

        if (array_key_exists('permissions', $data)) {
            $permissions = $this->permissionRepository->findManyByGuids($data['permissions'] ?? []);
            $role->syncPermissions($permissions);
        }

        return $role->load('permissions');
    }

    public function destroy(Role $role): void
    {
        if ($role->isTenant()) {
            throw new RoleImmutableException('Los roles de tenant no pueden eliminarse.');
        }

        $this->roleRepository->destroy($role);
    }

    public function syncPermissions(Role $role, array $permissionGuids): Role
    {
        $permissions = $this->permissionRepository->findManyByGuids($permissionGuids);
        $role->syncPermissions($permissions);

        return $role->load('permissions');
    }
}
