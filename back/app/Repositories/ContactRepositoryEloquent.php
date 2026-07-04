<?php

namespace App\Repositories;

use App\Contracts\Repositories\ContactRepositoryInterface;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;

class ContactRepositoryEloquent extends BaseRepositoryEloquent implements ContactRepositoryInterface
{
    protected function model(): string
    {
        return Contact::class;
    }

    public function findByGuid(string $guid): ?Contact
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function create(array $data): Contact
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(Model $contact, array $data): Contact
    {
        $contact->fill($data);
        $contact->save();
        /** @var Contact $contact */
        return $contact;
    }

    public function destroy(Model $contact): bool|null
    {
        return $contact->delete();
    }

    public function clearPrimaryForType(
        string $contactableType,
        int    $contactableId,
        string $type,
        ?int   $exceptId = null,
    ): void {
        $query = $this->newQuery()
            ->where('contactable_type', $contactableType)
            ->where('contactable_id', $contactableId)
            ->where('type', $type)
            ->where('is_primary', true);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $query->update(['is_primary' => false]);
    }
}
