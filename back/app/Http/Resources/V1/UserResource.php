<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'              => $this->guid,
            'first_name'        => $this->first_name,
            'last_name'         => $this->last_name,
            'name'              => $this->name,
            'email'             => $this->email,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'locked_at'         => $this->locked_at?->toISOString(),
            'last_login_at'     => $this->last_login_at?->toISOString(),
            'created_at'        => $this->created_at?->toISOString(),
            'status'            => $this->locked_at ? 'locked' : ($this->email_verified_at ? 'verified' : 'unverified'),
            'roles'             => $this->whenLoaded('roles', fn() => $this->roles->map(fn ($r) => ['guid' => $r->guid, 'name' => $r->name])),
            'permissions'       => $this->whenLoaded('permissions', fn() => $this->getAllPermissions()->pluck('name')),
            'profiles'          => $this->whenLoaded('profiles', fn() => $this->profiles->map(fn ($p) => [
                'guid'        => $p->guid,
                'role'        => ['guid' => $p->role->guid, 'name' => $p->role->name],
                'tenant_type' => $p->authenticatable_type,
                'tenant_guid' => $p->authenticatable?->guid,
                'tenant_name' => $p->authenticatable?->name,
            ])),
        ];
    }
}
