<?php

namespace App\Criteria\Shared;

use App\Contracts\QueryCriterion;
use Illuminate\Database\Eloquent\Builder;

class DateRangeCriteria implements QueryCriterion
{
    public function __construct(
        private ?string $from,
        private ?string $to,
        private string $column = 'created_at',
    ) {}

    public function apply(Builder $query): Builder
    {
        if ($this->from) {
            $query->whereDate($this->column, '>=', $this->from);
        }

        if ($this->to) {
            $query->whereDate($this->column, '<=', $this->to);
        }

        return $query;
    }
}
