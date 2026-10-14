<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'          => $this->guid,
            'client'        => ['guid' => $this->client->guid, 'name' => $this->client->name],
            'establishment' => ['guid' => $this->establishment->guid, 'name' => $this->establishment->name],
            'technique'     => ['guid' => $this->technique->guid, 'name' => $this->technique->name],
            'protocol'      => ['guid' => $this->protocol->guid, 'name' => $this->protocol->name],
            'comments'      => $this->comments,
            'cancelled_at'  => $this->cancelled_at?->toISOString(),
            'editable'      => $this->editable,
            'targets'       => ProgramTargetResource::collection($this->whenLoaded('targets')),
            'managers'      => $this->whenLoaded('managers', fn () => $this->managers->map(fn ($m) => [
                'guid'   => $m->guid,
                'name'   => $m->user->name,
                'role'   => $m->role->name,
                // DEC-16: evita que el frontend duplique la lista de roles vet vs. cliente
                // que ya vive en UserProfileService::VET_STAFF_ROLES.
                'origin' => in_array($m->role->name, \App\Services\UserProfileService::VET_STAFF_ROLES, true) ? 'vet' : 'client',
            ])),
            // Fase 3: solo en la respuesta de store/update (ProgramService::reconcileAlerts anota ambos
            // sobre el modelo en memoria). Advertencias autoritativas, calculadas sobre lo persistido.
            'warnings'       => $this->when($this->relationLoaded('alert_warnings'), fn () => array_map(
                fn ($warning) => $warning->toArray(),
                $this->getRelation('alert_warnings'),
            )),
            'reconciliation' => $this->when($this->relationLoaded('alert_report'), fn () => $this->getRelation('alert_report')->summary()),
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
