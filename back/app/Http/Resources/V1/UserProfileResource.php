<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'       => $this->guid,
            'user'       => $this->whenLoaded('user', fn () => [
                'guid'       => $this->user->guid,
                'name'       => $this->user->name,
                'first_name' => $this->user->first_name,
                'last_name'  => $this->user->last_name,
                'email'      => $this->user->email,
            ]),
            'role'       => $this->whenLoaded('role', fn () => [
                'guid' => $this->role->guid,
                'name' => $this->role->name,
            ]),
            'contacts'   => ContactResource::collection($this->whenLoaded('contacts')),
            'blocked_at' => $this->blocked_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
