<?php

namespace App\Listeners;

use App\Events\EstablishmentHealthPlanCancelledEvent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;

/**
 * RF-04 / DU2-04: cancelar un EstablishmentHealthPlan borra las Alert HealthPlanMonth
 * pendientes asociadas. Análogo a HandleProgramCancelledListener, sin la parte de crear
 * una alerta de aviso de cancelación (DU2-04 no la pide — ver DEC2-06).
 */
class CancelHealthPlanMonthAlertsListener
{
    public function handle(EstablishmentHealthPlanCancelledEvent $event): void
    {
        Alert::query()
            ->where('type', AlertType::HealthPlanMonth)
            ->where('subject_type', 'establishment_health_plan')
            ->where('subject_id', $event->plan->id)
            ->where('status', 'pending')
            ->get()
            ->each(fn (Alert $pending) => $pending->delete());
    }
}
