<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal client-staff option for the program form. Deliberately exposes no email,
 * contacts or internal ids: it is reachable by roles without `clients.staff.read`.
 */
class ProgramManagerOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid' => $this->guid,
            'name' => $this->user?->name,
            'role' => $this->role?->name,
        ];
    }
}
