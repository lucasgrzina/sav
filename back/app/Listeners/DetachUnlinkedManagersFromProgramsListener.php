<?php

namespace App\Listeners;

use App\Events\EstablishmentStaffUnlinkedEvent;
use App\Services\ProgramManagerLinkService;

/**
 * The manager detach itself happens inside EstablishmentService::syncStaff's transaction
 * (invariant "client manager => linked"). This listener runs after commit and regenerates
 * the pending alerts of each affected program, isolated per program: failures are logged
 * and do not affect the request nor the remaining programs (see ProgramManagerLinkService).
 */
class DetachUnlinkedManagersFromProgramsListener
{
    public function __construct(private readonly ProgramManagerLinkService $links) {}

    public function handle(EstablishmentStaffUnlinkedEvent $event): void
    {
        foreach ($event->programIds as $programId) {
            $this->links->regenerateAlerts($programId, $event->establishment->id, $event->profileIds);
        }
    }
}
