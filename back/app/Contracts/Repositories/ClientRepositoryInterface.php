<?php

namespace App\Contracts\Repositories;

use App\Models\Client;
use App\Models\Vet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface ClientRepositoryInterface
{
    public function findByGuid(string $guid): ?Client;

    /** Busca un client scoped al pivot de esta vet. Retorna null si no pertenece. */
    public function findByGuidForVet(string $guid, Vet $vet): ?Client;

    /** Crea el client y lo vincula a la vet en client_vet. */
    public function createForVet(array $data, Vet $vet): Client;

    public function update(Model $client, array $data): Client;

    /** Pagina los clients de una vet con filtros opcionales. */
    public function paginateForVet(Vet $vet, array $filters, int $perPage): LengthAwarePaginator;

    /** Elimina el registro en client_vet (NO borra el client). */
    public function detachFromVet(Client $client, Vet $vet): void;

    /**
     * Busca un client globalmente por tax_id exacto, sin filtro de vet.
     * Si hay múltiples con el mismo tax_id, retorna el más reciente.
     */
    public function findByTaxId(string $taxId): ?Client;

    /**
     * Verifica si un client ya está vinculado a una vet (fila en client_vet).
     */
    public function isLinkedToVet(Client $client, Vet $vet): bool;

    /**
     * Vincula un client existente a una vet (crea fila en client_vet).
     * El caller debe verificar que no esté ya vinculado antes de llamar este método.
     */
    public function attachToVet(Client $client, Vet $vet): void;

    /** Pagina todos los clients del sistema sin filtro de vet. */
    public function paginateAll(array $filters, int $perPage): LengthAwarePaginator;
}
