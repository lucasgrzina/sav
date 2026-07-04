<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthPlanTemplateActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'   => $this->guid,
            'name'   => $this->name,
            'months' => $this->pivot->months ?? [],  // array de enteros gracias al cast
        ];
    }
}
