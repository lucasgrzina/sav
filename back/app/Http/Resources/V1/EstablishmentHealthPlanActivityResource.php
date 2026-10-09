<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstablishmentHealthPlanActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'                 => $this->guid,
            'health_activity'      => [
                'guid' => $this->activity->guid,
                'name' => $this->activity->name,
            ],
            'month'                => $this->month,
            'due_date'             => $this->due_date->toDateString(),
            'status'               => $this->status,
            'require_confirmation' => $this->require_confirmation,
            'confirmed_at'         => $this->confirmed_at?->toISOString(),
            'confirmed_by'         => $this->whenLoaded('confirmedBy', fn () => $this->confirmedBy
                ? ['guid' => $this->confirmedBy->guid, 'name' => $this->confirmedBy->user->name]
                : null),
            'sort_order'           => $this->sort_order,
        ];
    }
}
