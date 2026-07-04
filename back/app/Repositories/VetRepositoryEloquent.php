<?php

namespace App\Repositories;

use App\Contracts\Repositories\VetRepositoryInterface;
use App\Models\Vet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class VetRepositoryEloquent extends BaseRepositoryEloquent implements VetRepositoryInterface
{
    protected function model(): string
    {
        return Vet::class;
    }

    public function findByGuid(string $guid): ?Vet
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findBySlug(string $slug): ?Vet
    {
        return $this->newQuery()->where('slug', $slug)->first();
    }

    public function create(array $data): Vet
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(Model $vet, array $data): Vet
    {
        $vet->fill($data);
        $vet->save();
        /** @var Vet $vet */
        return $vet;
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()->with(['country', 'documentType']);

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('tax_id', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('slug', 'like', '%' . $filters['search'] . '%');
            });
        }

        if (isset($filters['validated'])) {
            $query->when(
                filter_var($filters['validated'], FILTER_VALIDATE_BOOLEAN),
                fn ($q) => $q->whereNotNull('validated_at'),
                fn ($q) => $q->whereNull('validated_at'),
            );
        }

        if (isset($filters['suspended'])) {
            $query->when(
                filter_var($filters['suspended'], FILTER_VALIDATE_BOOLEAN),
                fn ($q) => $q->whereNotNull('suspended_at'),
                fn ($q) => $q->whereNull('suspended_at'),
            );
        }

        return $query->latest()->paginate($perPage);
    }
}
