<?php

namespace App\Criteria\Users;

use App\Contracts\QueryCriterion;
use Illuminate\Database\Eloquent\Builder;

class UserTypeCriteria implements QueryCriterion
{
    public function __construct(private ?string $type) {}

    public function apply(Builder $query): Builder
    {
        if ($this->type === 'platform') {
            return $query->whereHas('roles');
        }

        if ($this->type === 'tenant') {
            return $query->whereDoesntHave('roles');
        }

        return $query;
    }
}
