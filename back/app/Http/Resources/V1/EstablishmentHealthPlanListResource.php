<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstablishmentHealthPlanListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'             => $this->guid,
            'client'           => ['guid' => $this->client->guid, 'name' => $this->client->name],
            'establishment'    => ['guid' => $this->establishment->guid, 'name' => $this->establishment->name],
            'template'         => ['guid' => $this->template->guid, 'name' => $this->template->name],
            'year'             => $this->year,
            'starts_on'        => $this->starts_on->toDateString(),
            'ends_on'          => $this->ends_on->toDateString(),
            'cancelled_at'     => $this->cancelled_at?->toISOString(),
            'editable'         => $this->editable,
            'activities_count' => $this->activities_count ?? ($this->relationLoaded('activities') ? $this->activities->count() : 0),
            'pending_count'    => $this->pending_count ?? ($this->relationLoaded('activities') ? $this->activities->whereNull('confirmed_at')->count() : 0),
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}
