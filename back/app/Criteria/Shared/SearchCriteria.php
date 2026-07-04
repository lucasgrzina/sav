<?php

namespace App\Criteria\Shared;

use App\Contracts\QueryCriterion;
use Illuminate\Database\Eloquent\Builder;

class SearchCriteria implements QueryCriterion
{
    public function __construct(
        private ?string $search,
        private string $column = 'name',
    ) {}

    public function apply(Builder $query): Builder
    {
        if ($this->search) {
            $query->where($this->column, 'like', "%{$this->search}%");
        }

        return $query;
    }
}
