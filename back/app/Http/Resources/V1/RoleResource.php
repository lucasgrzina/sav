<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    private const LABELS = [
        'client-owner'          => 'Propietario',
        'client-manager'        => 'Encargado',
        'client-administrative' => 'Administrativo',
        'vet'                   => 'Veterinario',
        'vet-administrative'    => 'Administrativo de Veterinario',
        'vet-assistant'         => 'Asistente de Veterinario',
    ];

    public function toArray(Request $request): array
    {
        return [
            'guid'        => $this->guid,
            'name'        => $this->name,
            'label'       => self::LABELS[$this->name] ?? $this->name,
            'type'        => $this->type,
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'users_count'       => $this->whenCounted('users'),
            'permissions_count' => $this->whenCounted('permissions'),
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
