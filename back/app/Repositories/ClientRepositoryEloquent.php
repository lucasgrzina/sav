<?php

namespace App\Repositories;

use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Models\Client;
use App\Models\Vet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class ClientRepositoryEloquent extends BaseRepositoryEloquent implements ClientRepositoryInterface
{
    protected function model(): string
    {
        return Client::class;
    }

    public function findByGuid(string $guid): ?Client
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByGuidForVet(string $guid, Vet $vet): ?Client
    {
        // CRÍTICO: filtra por el pivot para garantizar aislamiento de tenant
        return $vet->clients()
            ->where('clients.guid', $guid)
            ->first();
    }

    public function createForVet(array $data, Vet $vet): Client
    {
        /** @var Client $client */
        $client = $this->model->newQuery()->create($data);
        // Vincular al pivot con timestamps
        $vet->clients()->attach($client->id);
        return $client;
    }

    public function update(Model $client, array $data): Client
    {
        $client->fill($data);
        $client->save();
        /** @var Client $client */
        return $client;
    }

    public function paginateForVet(Vet $vet, array $filters, int $perPage): LengthAwarePaginator
    {
        // Base: clients de esta vet via pivot
        $query = $vet->clients()->with(['country', 'documentType']);

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('clients.name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('clients.tax_id', 'like', '%' . $filters['search'] . '%');
            });
        }

        return $query->latest('clients.created_at')->paginate($perPage);
    }

    public function detachFromVet(Client $client, Vet $vet): void
    {
        $vet->clients()->detach($client->id);
    }

    public function findByTaxId(string $taxId): ?Client
    {
        return $this->newQuery()
            ->where('tax_id', $taxId)
            ->latest()
            ->first();
    }

    public function isLinkedToVet(Client $client, Vet $vet): bool
    {
        return $vet->clients()
            ->where('clients.id', $client->id)
            ->exists();
    }

    public function attachToVet(Client $client, Vet $vet): void
    {
        $vet->clients()->attach($client->id);
    }

    public function paginateAll(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->newQuery()->with(['country', 'documentType']);

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name',   'like', '%' . $filters['search'] . '%')
                  ->orWhere('tax_id', 'like', '%' . $filters['search'] . '%');
            });
        }

        return $query->latest('created_at')->paginate($perPage);
    }
}
