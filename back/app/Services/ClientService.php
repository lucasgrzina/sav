<?php

namespace App\Services;

use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Models\Client;
use App\Models\Vet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ClientService
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private ContactService            $contactService,
    ) {}

    public function paginate(Vet $vet, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->clientRepository->paginateForVet($vet, $filters, $perPage);
    }

    /**
     * Crea el client, lo vincula a la vet, y crea contactos iniciales si se proveen.
     * Todo dentro de una transacción DB.
     */
    public function create(Vet $vet, array $data): Client
    {
        return DB::transaction(function () use ($vet, $data) {
            $contacts = $data['contacts'] ?? [];
            unset($data['contacts']);

            // Resolver IDs internos desde guids recibidos
            $data = $this->resolveIds($data);

            $client = $this->clientRepository->createForVet($data, $vet);

            foreach ($contacts as $contactData) {
                $this->contactService->create($client, $contactData);
            }

            return $client;
        });
    }

    public function findByGuidForVet(string $guid, Vet $vet): ?Client
    {
        return $this->clientRepository->findByGuidForVet($guid, $vet);
    }

    public function update(Client $client, array $data): Client
    {
        return DB::transaction(function () use ($client, $data) {
            $contacts = array_key_exists('contacts', $data) ? $data['contacts'] : null;
            unset($data['contacts']);

            $data   = $this->resolveIds($data);
            $client = $this->clientRepository->update($client, $data);

            if ($contacts !== null) {
                $this->contactService->syncContacts($client, $contacts);
            }

            return $client->load('contacts');
        });
    }

    /**
     * Desvincula el client de la vet. NO borra el registro de clients.
     */
    public function detach(Client $client, Vet $vet): void
    {
        $this->clientRepository->detachFromVet($client, $vet);
    }

    /**
     * Busca un client por guid globalmente (sin scope de vet).
     * Usar solo para flujos de lookup/link donde el client puede no estar vinculado aún.
     */
    public function findByGuid(string $guid): ?Client
    {
        return $this->clientRepository->findByGuid($guid);
    }

    /**
     * Busca un client globalmente por tax_id.
     * Retorna el client si existe + flag de si ya está vinculado a la vet dada.
     *
     * @return array{ found: bool, client: ?Client, already_linked: bool }
     */
    public function lookupByTaxId(string $taxId, Vet $vet): array
    {
        $client = $this->clientRepository->findByTaxId($taxId);

        if (!$client) {
            return ['found' => false, 'client' => null, 'already_linked' => false];
        }

        $alreadyLinked = $this->clientRepository->isLinkedToVet($client, $vet);

        return [
            'found'          => true,
            'client'         => $client,
            'already_linked' => $alreadyLinked,
        ];
    }

    /**
     * Busca un client por tax_id y verifica si ya está vinculado a la vet
     * identificada por guid. Diseñado para el contexto admin (sin Vet resuelto por middleware).
     *
     * @return array{ found: bool, client: ?Client, already_linked: bool }
     */
    public function lookupForVet(string $taxId, string $vetGuid): array
    {
        $client = $this->clientRepository->findByTaxId($taxId);

        if (!$client) {
            return ['found' => false, 'client' => null, 'already_linked' => false];
        }

        // Resolver la vet por guid para verificar el vínculo
        $vet = Vet::where('guid', $vetGuid)->first();

        // Si la vet no existe, el cliente "existe pero no vinculado" es la respuesta más segura.
        // El controller valida la existencia de la vet antes de llamar este método.
        $alreadyLinked = $vet
            ? $this->clientRepository->isLinkedToVet($client, $vet)
            : false;

        return [
            'found'          => true,
            'client'         => $client,
            'already_linked' => $alreadyLinked,
        ];
    }

    /**
     * Vincula un client existente a una vet.
     * Lanza RuntimeException si ya está vinculado.
     */
    public function linkToVet(Client $client, Vet $vet): void
    {
        if ($this->clientRepository->isLinkedToVet($client, $vet)) {
            throw new \RuntimeException('Este cliente ya está vinculado a esta veterinaria.');
        }

        $this->clientRepository->attachToVet($client, $vet);
    }

    /**
     * Pagina todos los clients del sistema sin filtro de vet (contexto admin).
     */
    public function paginateAll(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->clientRepository->paginateAll($filters, $perPage);
    }

    /**
     * Crea un client sin vincularlo a ninguna vet (contexto admin).
     * El client queda huérfano hasta que el admin lo vincule manualmente.
     */
    public function createWithoutVet(array $data): Client
    {
        return DB::transaction(function () use ($data) {
            $contacts = $data['contacts'] ?? [];
            unset($data['contacts']);

            $data   = $this->resolveIds($data);
            $client = $this->clientRepository->create($data);

            foreach ($contacts as $contactData) {
                $this->contactService->create($client, $contactData);
            }

            return $client;
        });
    }

    /**
     * Convierte country_guid y document_type_guid a sus IDs internos.
     * Solo convierte los campos que estén presentes (para soportar updates parciales).
     */
    private function resolveIds(array $data): array
    {
        if (isset($data['country_guid'])) {
            $country = \App\Models\Country::where('guid', $data['country_guid'])->firstOrFail();
            $data['country_id'] = $country->id;
            unset($data['country_guid']);
        }

        if (isset($data['document_type_guid'])) {
            $docType = \App\Models\DocumentType::where('guid', $data['document_type_guid'])->firstOrFail();
            $data['document_type_id'] = $docType->id;
            unset($data['document_type_guid']);
        }

        return $data;
    }
}
