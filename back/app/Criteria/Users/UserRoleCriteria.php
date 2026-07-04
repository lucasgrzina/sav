<?php

namespace App\Criteria\Users;

use App\Contracts\QueryCriterion;
use Illuminate\Database\Eloquent\Builder;

class UserRoleCriteria implements QueryCriterion
{
    public function __construct(private ?string $roleGuid, private ?string $userType) {}

    public function apply(Builder $query): Builder
    {
        if (! $this->roleGuid) {
            return $query;
        }

        $roleGuid = $this->roleGuid;

        if ($this->userType === 'tenant') {
            return $query->whereHas('profiles.role', fn(Builder $q) => $q->where('guid', $roleGuid));
        }

        return $query->whereHas('roles', fn(Builder $q) => $q->where('guid', $roleGuid));
    }
}
