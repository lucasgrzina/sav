<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TechniqueListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'             => $this->guid,
            'name'             => $this->name,
            'type'             => $this->type,
            'target_date_name' => $this->target_date_name,
            'protocols_name'   => $this->protocols_name,
            'children_count'   => $this->children_count ?? 0,
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
