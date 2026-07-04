<?php

namespace App\Criteria\Users;

use App\Contracts\QueryCriterion;
use Illuminate\Database\Eloquent\Builder;

class UserSearchCriteria implements QueryCriterion
{
    public function __construct(private ?string $search) {}

    public function apply(Builder $query): Builder
    {
        if (! $this->search) {
            return $query;
        }

        $search = $this->search;

        return $query->where(function (Builder $q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        });
    }
}
