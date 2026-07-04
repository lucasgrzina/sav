<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'           => $this->guid,
            'name'           => $this->name,
            'tax_id'         => $this->tax_id,
            'address'        => $this->address,
            'city'           => $this->city,
            'state'          => $this->state,
            'zip_code'       => $this->zip_code,
            'country'        => new CountryResource($this->whenLoaded('country')),
            'document_type'  => new DocumentTypeResource($this->whenLoaded('documentType')),
            'contacts'       => ContactResource::collection($this->whenLoaded('contacts')),
            'establishments' => EstablishmentResource::collection($this->whenLoaded('establishments')),
            'vets'           => VetResource::collection($this->whenLoaded('vets')),
            'created_at'     => $this->created_at?->toISOString(),
        ];
    }
}
