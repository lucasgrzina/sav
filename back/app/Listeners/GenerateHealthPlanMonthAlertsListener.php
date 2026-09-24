<?php

namespace App\Listeners;

use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Events\EstablishmentHealthPlanInstantiatedEvent;
use App\Models\Country;
use App\Models\EstablishmentHealthPlan;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Notifications\Services\AlertRecipientFactory;
use App\Support\HealthPlanYear;
use Illuminate\Support\Collection;

/**
 * RF-01: genera una Alert HealthPlanMonth por cada mes distinto con actividades
 * materializadas al instanciar un EstablishmentHealthPlan. Análogo a
 * GenerateProgramTaskDueAlertsListener, pero agrupando por mes en vez de iterar tareas
 * (DU2-01: una sola alerta por mes, no una por actividad).
 */
class GenerateHealthPlanMonthAlertsListener
{
    public function __construct(
        private readonly UserProfileRepositoryInterface $userProfiles,
        private readonly AlertRecipientFactory $recipientFactory,
    ) {}

    public function handle(EstablishmentHealthPlanInstantiatedEvent $event): void
    {
        $plan = $event->plan;
        $country = $plan->client->country;
        $months = $plan->activities->pluck('month')->unique()->sort()->values();

        if ($months->isEmpty()) {
            return; // RF-01 AC#4 — plan sin actividades materializadas (caso borde defensivo)
        }

        $recipients = $this->userProfiles->listByRoleForVet($plan->vet, 'vet'); // DU2-02

        if ($recipients->isEmpty()) {
            return; // sin destinatarios, no tiene sentido crear la Alert
        }

        foreach ($months as $month) {
            $this->generateAlertForMonth($plan, $country, (int) $month, $recipients);
        }
    }

    private function generateAlertForMonth(EstablishmentHealthPlan $plan, Country $country, int $month, Collection $recipients): void
    {
        $scheduledAt = HealthPlanYear::dateForMonth($country, $plan->year, $month)
            ->subDays(7)
            ->setTime(16, 0);

        if ($scheduledAt->isPast()) {
            return; // RF-01 AC#3 — descarte silencioso, sin log (mismo criterio que ProgramTaskDue)
        }

        $alert = new Alert([
            'type' => AlertType::HealthPlanMonth,
            'payload' => ['month' => $month],
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
            'vet_id' => $plan->vet_id,
        ]);
        $alert->subject()->associate($plan);
        $alert->save();

        $this->recipientFactory->createForManagers($alert, $recipients);
    }
}
