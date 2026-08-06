<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProtocolTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'         => $this->guid,
            'description'  => $this->description,
            'days_offset'  => $this->days_offset,
            'time_of_day'  => $this->time_of_day,
            'time'         => $this->time ? substr((string) $this->time, 0, 5) : null,
            'important'    => $this->important,
            'sort_order'   => $this->sort_order,
            'alerts'       => ProtocolTaskAlertResource::collection($this->whenLoaded('alerts')),
        ];
    }
}
