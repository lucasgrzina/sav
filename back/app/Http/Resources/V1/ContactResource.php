<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'           => $this->guid,
            'type'           => $this->type->value,
            'label'          => $this->label,
            'value'          => $this->value,
            'is_primary'     => $this->is_primary,
            'use_for_alerts' => $this->use_for_alerts,
            'created_at'     => $this->created_at?->toISOString(),
        ];
    }
}
