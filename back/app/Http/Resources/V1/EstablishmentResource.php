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
            'zip_code'   => $this->zip_code,
            'latitude'   => $this->latitude,
            'longitude'  => $this->longitude,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
