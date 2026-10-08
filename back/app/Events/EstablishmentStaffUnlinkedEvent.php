<?php

namespace App\Events;

use App\Models\Establishment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched AFTER the unlink transaction has committed. Managers were already detached
 * from the affected programs inside that transaction; listeners only regenerate alerts.
 */
class EstablishmentStaffUnlinkedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @param int[] $profileIds  internal UserProfile ids that were unlinked
     * @param int[] $programIds  active programs that lost at least one of those managers
     */
    public function __construct(
        public readonly Establishment $establishment,
        public readonly array $profileIds,
        public readonly array $programIds = [],
    ) {}
}
