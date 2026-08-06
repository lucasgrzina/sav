<?php

namespace App\Contracts\Repositories;

use App\Models\Animal;
use Illuminate\Database\Eloquent\Collection;

interface AnimalRepositoryInterface
{
    public function findByGuid(string $guid): ?Animal;

    /** Resuelve una selección de guids (usada al guardar un Program). */
    public function findManyByGuids(array $guids): Collection;

    public function create(array $data): Animal;

    /**
     * Resuelve o crea un Animal para un rp dentro del scope de UN cliente (DEC-07, reglas 1 y 3).
     * Si ya existe una fila (client_id, rp), la reutiliza en vez de fallar por el unique constraint.
     * Si el mismo rp existe para OTRO cliente, no interfiere: crea una fila nueva propia de este cliente.
     */
    public function firstOrCreateForClient(int $clientId, string $rp): Animal;

    /**
     * Búsqueda para el picker de frontend (DEC-10). Match parcial sobre rp y name,
     * scoped SIEMPRE a un client_id — nunca se expone búsqueda cross-cliente.
     */
    public function searchForClient(int $clientId, ?string $search, int $limit = 20): Collection;
}
