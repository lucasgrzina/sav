<?php

namespace App\Services;

use App\Contracts\Repositories\VetRepositoryInterface;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Vet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class VetService
{
    public function __construct(
        private VetRepositoryInterface $vetRepository,
        private ContactService         $contactService,
    ) {}

    public function findByGuid(string $guid): ?Vet
    {
        return $this->vetRepository->findByGuid($guid);
    }

    public function findBySlug(string $slug): ?Vet
    {
        return $this->vetRepository->findBySlug($slug);
    }

    public function create(array $data): Vet
    {
        $contacts = $data['contacts'] ?? [];
        unset($data['contacts']);

        $data['slug']             = $this->generateUniqueSlug($data['name']);
        $data['country_id']       = Country::where('guid', $data['country_guid'])->value('id');
        $data['document_type_id'] = DocumentType::where('guid', $data['document_type_guid'])->value('id');
        unset($data['country_guid'], $data['document_type_guid']);

        $vet = $this->vetRepository->create($data);

        foreach ($contacts as $contact) {
            $this->contactService->create($vet, $contact);
        }

        return $vet->load('contacts');
    }

    public function update(Vet $vet, array $data): Vet
    {
        $contacts = array_key_exists('contacts', $data) ? $data['contacts'] : null;
        unset($data['contacts']);

        if (isset($data['document_type_guid'])) {
            $data['document_type_id'] = DocumentType::where('guid', $data['document_type_guid'])->value('id');
            unset($data['document_type_guid']);
        }

        if (isset($data['name']) && $data['name'] !== $vet->name) {
            $data['slug'] = $this->generateUniqueSlug($data['name'], $vet->id);
        }

        $vet = $this->vetRepository->update($vet, $data);

        if ($contacts !== null) {
            $this->contactService->syncContacts($vet, $contacts);
        }

        return $vet->load('contacts');
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->vetRepository->paginate($filters, $perPage);
    }

    public function validate(Vet $vet, User $validatedBy): Vet
    {
        return $this->vetRepository->update($vet, [
            'validated_at' => Carbon::now(),
            'validated_by' => $validatedBy->id,
        ]);
    }

    public function suspend(Vet $vet): Vet
    {
        return $this->vetRepository->update($vet, [
            'suspended_at' => Carbon::now(),
        ]);
    }

    public function unsuspend(Vet $vet): Vet
    {
        return $this->vetRepository->update($vet, [
            'suspended_at' => null,
        ]);
    }

    /**
     * Genera un slug único. Si hay colisión, agrega sufijo numérico (-2, -3, ...).
     *
     * @param string   $name      Nombre de la veterinaria
     * @param int|null $exceptId  ID a excluir del check de unicidad (para updates)
     */
    private function generateUniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base    = Str::slug($name);
        $slug    = $base;
        $counter = 2;

        while (true) {
            $exists = Vet::where('slug', $slug)
                ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
                ->exists();

            if (!$exists) {
                break;
            }

            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
