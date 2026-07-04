<?php

namespace App\Criteria\Users;

use App\Contracts\QueryCriterion;
use App\Models\Vet;
use Illuminate\Database\Eloquent\Builder;

class UserVetCriteria implements QueryCriterion
{
    public function __construct(private ?string $vetGuid) {}

    public function apply(Builder $query): Builder
    {
        if (! $this->vetGuid) {
            return $query;
        }

        $vetGuid = $this->vetGuid;

        return $query->whereHas('profiles', function (Builder $q) use ($vetGuid) {
            $q->where('authenticatable_type', 'vet')
              ->whereIn('authenticatable_id', Vet::where('guid', $vetGuid)->select('id'));
        });
    }
}
