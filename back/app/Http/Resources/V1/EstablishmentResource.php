<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstablishmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'       => $this->guid,
            'name'       => $this->name,
            'renspa'     => $this->renspa,
            'address'    => $this->address,
            'city'       => $this->city,
            'state'      => $this->state,
            'province'   => $this->whenLoaded('province', fn () => $this->province ? [
                'guid' => $this->province->guid,
                'name' => $this->province->name,
            ] : null),
            'zip_code'   => $this->zip_code,
            'latitude'   => $this->latitude,
            'longitude'  => $this->longitude,
            'created_at' => $this->created_at?->toISOString(),
            // Staff carries personal data (email, names): only serialized for actors with
            // `clients.staff.read` (tenant vet / admin). Others (e.g. vet-assistant) get `staff_count` only.
            'staff'       => $this->when(
                $request->user()?->can('clients.staff.read') && $this->relationLoaded('staff'),
                fn () => UserProfileResource::collection($this->staff),
            ),
            'staff_count' => $this->whenCounted('staff'),
        ];
    }
}
