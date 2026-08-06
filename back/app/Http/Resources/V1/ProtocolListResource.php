<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProtocolListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'        => $this->guid,
            'name'        => $this->name,
            'color'       => $this->color,
            'technique'   => ['guid' => $this->technique->guid, 'name' => $this->technique->name],
            'country'     => $this->country ? ['guid' => $this->country->guid, 'name' => $this->country->name] : null,
            'is_global'   => $this->country_id === null,
            'tasks_count' => $this->tasks_count ?? 0,
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
