<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TutorialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'        => $this->guid,
            'title'       => $this->title,
            'description' => $this->description,
            'source'      => $this->source,
            'code'        => $this->code,
            'order'       => $this->order,
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
