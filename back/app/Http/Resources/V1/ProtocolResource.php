<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProtocolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'             => $this->guid,
            'name'             => $this->name,
            'color'            => $this->color,
            'technique'        => ['guid' => $this->technique->guid, 'name' => $this->technique->name],
            'country'          => $this->country ? ['guid' => $this->country->guid, 'name' => $this->country->name] : null,
            'is_global'        => $this->country_id === null,
            'tasks_count'      => $this->tasks_count ?? ($this->relationLoaded('tasks') ? $this->tasks->count() : 0),
            'created_by_type'  => $this->created_by_type,
            'tasks'            => ProtocolTaskResource::collection($this->whenLoaded('tasks')),
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
