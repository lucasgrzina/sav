<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'         => $this->guid,
            'name'         => $this->name,
            'iso_code'     => $this->iso_code,
            'phone_prefix' => $this->phone_prefix,
        ];
    }
}
