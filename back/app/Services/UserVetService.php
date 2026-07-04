<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Database\Eloquent\Collection;

class UserVetService
{
    public function getActiveVetsForUser(User $user): Collection
    {
        return UserProfile::query()
            ->with(['role.permissions', 'authenticatable'])
            ->where('user_id', $user->id)
            ->whereHasMorph('authenticatable', Vet::class, function ($query) {
                $query->whereNotNull('validated_at')
                      ->whereNull('suspended_at');
            })
            ->get();
    }
}
