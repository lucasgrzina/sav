<?php

namespace App\Contracts\Repositories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface ContactRepositoryInterface
{
    public function findByGuid(string $guid): ?Contact;
    public function create(array $data): Contact;
    public function update(Model $contact, array $data): Contact;
    public function destroy(Model $contact): bool|null;

    /**
     * Pone is_primary = false en todos los contactos del mismo
     * contactable + type, excepto el excluido por ID.
     */
    public function clearPrimaryForType(
        string $contactableType,
        int    $contactableId,
        string $type,
        ?int   $exceptId = null,
    ): void;
}
