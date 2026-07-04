<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserVetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Vet $vet */
        $vet = $this->authenticatable;

        return [
            'guid'      => $vet->guid,
            'name'      => $vet->name,
            'slug'      => $vet->slug,
            'logo_path' => $vet->logo_path,
            'is_active' => $vet->validated_at !== null && $vet->suspended_at === null,
            'role'      => [
                'name'        => $this->role->name,
                'permissions' => $this->role->permissions->pluck('name'),
            ],
        ];
    }
}
