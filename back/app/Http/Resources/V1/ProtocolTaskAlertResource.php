<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProtocolTaskAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'                  => $this->guid,
            'offset_days'           => $this->offset_days,
            'time_of_day'           => $this->time_of_day,
            'time'                  => $this->time ? substr((string) $this->time, 0, 5) : null,
            'roles'                 => $this->roles,
            'message'               => $this->message,
            'require_confirmation'  => $this->require_confirmation,
            'sort_order'            => $this->sort_order,
        ];
    }
}
