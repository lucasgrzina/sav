<?php

namespace App\Criteria\Users;

use App\Contracts\QueryCriterion;
use Illuminate\Database\Eloquent\Builder;

class UserStatusCriteria implements QueryCriterion
{
    public function __construct(private ?string $status) {}

    public function apply(Builder $query): Builder
    {
        if (! $this->status) {
            return $query;
        }

        return match ($this->status) {
            'verified'   => $query->whereNotNull('email_verified_at')->whereNull('locked_at'),
            'unverified' => $query->whereNull('email_verified_at'),
            'locked'     => $query->whereNotNull('locked_at'),
            default      => $query,
        };
    }
}
