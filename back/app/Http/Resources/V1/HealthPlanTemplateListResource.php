<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthPlanTemplateListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'             => $this->guid,
            'name'             => $this->name,
            'category'         => $this->whenLoaded('category', fn() => [
                'guid' => $this->category->guid,
                'name' => $this->category->name,
            ]),
            'activities_count' => $this->activities_count ?? 0,
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
