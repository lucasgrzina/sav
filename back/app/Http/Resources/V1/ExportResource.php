<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'            => $this->guid,
            'type'            => $this->type->value,
            'type_label'      => $this->type->label(),
            'format'          => $this->format->value,
            'status'          => $this->status->value,
            'file_name'       => $this->file_name,
            'is_downloadable' => $this->isDownloadable(),
            'error_message'   => $this->error_message,
            'expires_at'      => $this->expires_at?->toISOString(),
            'created_at'      => $this->created_at?->toISOString(),
        ];
    }
}
