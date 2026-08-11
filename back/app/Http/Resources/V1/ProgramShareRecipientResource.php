<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramShareRecipientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'         => $this->guid,
            'name'         => $this->user->name,
            'role'         => $this->role->name,
            'has_whatsapp' => (bool) $this->has_whatsapp,
        ];
    }
}
