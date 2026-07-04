<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'       => $this->guid,
            'name'       => $this->name,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
