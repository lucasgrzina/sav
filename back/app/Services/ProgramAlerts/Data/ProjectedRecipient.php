<?php

namespace App\Services\ProgramAlerts\Data;

use App\Models\UserProfile;

final readonly class ProjectedRecipient
{
    public function __construct(
        public UserProfile $profile,
        public string $role,
    ) {}
}
