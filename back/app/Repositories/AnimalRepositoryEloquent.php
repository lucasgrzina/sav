<?php

namespace App\Repositories;

use App\Contracts\Repositories\AnimalRepositoryInterface;
use App\Models\Animal;
use Illuminate\Database\Eloquent\Collection;

class AnimalRepositoryEloquent extends BaseRepositoryEloquent implements AnimalRepositoryInterface
{
    protected function model(): string
    {
        return Animal::class;
    }

    public function findByGuid(string $guid): ?Animal
    {
        /** @var Animal|null */
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findManyByGuids(array $guids): Collection
    {
        return $this->newQuery()->whereIn('guid', $guids)->get();
    }

    public function create(array $data): Animal
    {
        /** @var Animal */
        return parent::create($data);
    }

    public function firstOrCreateForClient(int $clientId, string $rp): Animal
    {
        /** @var Animal */
        return $this->newQuery()->firstOrCreate(
            ['client_id' => $clientId, 'rp' => $rp],
            ['type' => 'livestock'],
        );
    }

    public function searchForClient(int $clientId, ?string $search, int $limit = 20): Collection
    {
        return $this->newQuery()
            ->where('client_id', $clientId)
            ->when($search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('rp', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('rp')
            ->limit($limit)
            ->get();
    }
}
