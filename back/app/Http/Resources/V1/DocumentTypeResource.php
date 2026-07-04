<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'    => $this->guid,
            'name'    => $this->name,
            'country' => new CountryResource($this->whenLoaded('country')),
        ];
    }
}
