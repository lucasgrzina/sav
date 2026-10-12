<?php

namespace App\Listeners;

use App\Events\ProgramTargetsChangedEvent;
use App\Services\ProgramAlerts\ProgramAlertReconciler;

/**
 * Entry point for callers that only fire ProgramTargetsChangedEvent (ProgramManagerLinkService).
 * All the logic lives in ProgramAlertReconciler; ProgramService calls the reconciler directly
 * because it needs the report.
 */
class GenerateProgramTaskDueAlertsListener
{
    public function __construct(private readonly ProgramAlertReconciler $reconciler) {}

    public function handle(ProgramTargetsChangedEvent $event): void
    {
        $this->reconciler->reconcile($event->program, 'managers_unlinked');
    }
}
