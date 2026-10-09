<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

class EstablishmentHealthPlanResource extends EstablishmentHealthPlanListResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'activities' => EstablishmentHealthPlanActivityResource::collection($this->whenLoaded('activities')),
        ]);
    }
}
