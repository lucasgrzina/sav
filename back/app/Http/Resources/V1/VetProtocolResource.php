<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VetProtocolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'        => $this->guid,
            'name'        => $this->name,
            'color'       => $this->color,
            'technique'   => ['guid' => $this->technique->guid, 'name' => $this->technique->name],
            'is_global'   => $this->vet_id === null,
            'is_own'      => $this->vet_id !== null,
            'tasks_count' => $this->tasks_count ?? ($this->relationLoaded('tasks') ? $this->tasks->count() : 0),
            'tasks'       => ProtocolTaskResource::collection($this->whenLoaded('tasks')),
            // Only set by detail/edit endpoints (one query); never computed for lists.
            'active_programs_count' => $this->when(
                array_key_exists('active_programs_count', $this->resource->getAttributes()),
                fn () => (int) $this->resource->getAttributes()['active_programs_count'],
            ),
            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }
}
