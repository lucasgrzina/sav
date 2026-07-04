<?php

namespace App\Repositories;

use App\Contracts\Repositories\TutorialRepositoryInterface;
use App\Models\Tutorial;
use Illuminate\Database\Eloquent\Collection;

class TutorialRepositoryEloquent extends BaseRepositoryEloquent implements TutorialRepositoryInterface
{
    protected function model(): string
    {
        return Tutorial::class;
    }

    public function listOrdered(): Collection
    {
        return $this->newQuery()->orderBy('order')->get();
    }

    public function findByGuid(string $guid): ?Tutorial
    {
        /** @var Tutorial|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function create(array $data): Tutorial
    {
        /** @var Tutorial */
        return parent::create($data);
    }
}
