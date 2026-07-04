<?php

namespace App\Traits;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasContacts
{
    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function primaryContact(string $type): ?Contact
    {
        return $this->contacts()
            ->where('type', $type)
            ->where('is_primary', true)
            ->first();
    }
}
