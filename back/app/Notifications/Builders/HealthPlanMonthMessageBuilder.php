<?php

namespace App\Notifications\Builders;

use App\Models\EstablishmentHealthPlan;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\Payloads\HealthPlanMonthPayload;
use App\Notifications\Data\PushContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;

/**
 * RF-02: a diferencia de los demás builders, recalcula el estado ACTUAL de las
 * actividades pendientes contra la base de datos (no el payload congelado del Alert) y
 * aborta el envío (retorna null) si ya no queda ninguna pendiente para ese (plan, mes) —
 * ver DeliverAlertJob, que trata ese caso como DeliveryStatus::Suppressed.
 */
final class HealthPlanMonthMessageBuilder implements AlertMessageBuilder
{
    private const MONTH_NAMES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    public function type(): AlertType
    {
        return AlertType::HealthPlanMonth;
    }

    public function build(Alert $alert, Recipient $recipient): ?MessageContent
    {
        /** @var EstablishmentHealthPlan $plan */
        $plan = $alert->subject;
        $payload = HealthPlanMonthPayload::from($alert->payload);

        // RF-02: se consulta el estado ACTUAL en DB, no un snapshot guardado en el payload.
        $pendingActivities = $plan->activities()
            ->where('month', $payload->month)
            ->whereNull('confirmed_at')
            ->with('activity')
            ->orderBy('sort_order')
            ->get();

        if ($pendingActivities->isEmpty()) {
            return null; // RF-02 AC#2 — todo confirmado entre creación y envío: no se envía
        }

        $monthName = self::MONTH_NAMES[$payload->month] ?? (string) $payload->month;
        $activitiesList = $pendingActivities->pluck('activity.name')->implode(', ');

        if ($recipient->channel === Channel::Email) {
            return new EmailContent(
                subject: "Recordatorio: plan sanitario de {$plan->establishment->name}",
                body: "Hola {$recipient->name}, en {$monthName} corresponde: {$activitiesList} "
                    . "({$plan->establishment->name}, {$plan->client->name}).",
            );
        }

        if ($recipient->channel === Channel::Push) {
            return new PushContent(
                title: 'Plan sanitario — actividades del mes',
                body: "{$plan->establishment->name}: {$activitiesList}",
                tag: "alert-{$alert->guid}",
                url: "establishment-health-plans/{$plan->guid}",
                data: [
                    'requires_confirmation' => false,
                    'alert_guid' => $alert->guid,
                    'establishment_health_plan_guid' => $plan->guid,
                ],
            );
        }

        return new TemplateContent(
            type: AlertType::HealthPlanMonth,
            variables: [
                '1' => $recipient->name,
                '2' => $monthName,
                '3' => $activitiesList,
                '4' => $plan->template->name,
                '5' => $plan->template->category->name,
                '6' => $plan->client->name,
                '7' => $plan->establishment->name,
            ],
        );
    }
}
