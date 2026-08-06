<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProtocolSimulationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'protocol'  => [
                'guid' => $this['protocol']->guid,
                'name' => $this['protocol']->name,
            ],
            'base_date' => $this['base_date'],
            'tasks'     => $this['tasks'],
            'alerts'    => $this['alerts'],
        ];
    }
}
