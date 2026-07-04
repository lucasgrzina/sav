<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // El pivot read_at se carga condicionalmente desde la relación recipients
        // filtrada por user_id (ver repositorio). Si no está cargada, read_at = null.
        $readAt = null;
        if ($this->relationLoaded('recipients') && $this->recipients->isNotEmpty()) {
            $readAt = $this->recipients->first()?->pivot?->read_at?->toISOString();
        }

        return [
            'guid'       => $this->guid,
            'payload'    => $this->payload,
            'read_at'    => $readAt,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
