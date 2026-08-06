<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'             => $this->guid,
            'client'           => ['guid' => $this->client->guid, 'name' => $this->client->name],
            'establishment'    => ['guid' => $this->establishment->guid, 'name' => $this->establishment->name],
            'technique'        => ['guid' => $this->technique->guid, 'name' => $this->technique->name],
            'protocol'         => ['guid' => $this->protocol->guid, 'name' => $this->protocol->name],
            'cancelled_at'     => $this->cancelled_at?->toISOString(),
            'editable'         => $this->editable,
            'targets_count'    => $this->targets_count,
            'next_target_date' => $this->next_target_date,
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}
