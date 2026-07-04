<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface QueryCriterion
{
    public function apply(Builder $query): Builder;
}
