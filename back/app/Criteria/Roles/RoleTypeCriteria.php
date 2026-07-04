<?php

namespace App\Criteria\Roles;

use App\Contracts\QueryCriterion;
use Illuminate\Database\Eloquent\Builder;

class RoleTypeCriteria implements QueryCriterion
{
    public function __construct(
        private ?string $type,
    ) {}

    public function apply(Builder $query): Builder
    {
        if ($this->type !== null) {
            $query->where('type', $this->type);
        }

        return $query;
    }
}
