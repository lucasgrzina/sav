<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code'        => $this->code,
            'value'       => $this->parsed_value,
            'type'        => $this->type,
            'description' => $this->description,
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }
}
