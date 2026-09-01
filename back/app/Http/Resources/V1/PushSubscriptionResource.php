<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PushSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // DEC-02: cloud_id ES el guid estándar del proyecto, renombrado solo en este
        // contrato externo puntual que pidió mobile. No es un identificador nuevo.
        return ['cloud_id' => $this->guid];
    }
}
