<?php

namespace App\Services\ProgramAlerts;

use App\Services\ProgramAlerts\Data\ExistingAlertIndex;
use App\Services\ProgramAlerts\Data\ProgramProjection;
use App\Services\ProgramAlerts\Data\ProjectedAlert;
use App\Services\ProgramAlerts\Data\ProjectedRecipient;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\Data\Warning;
use App\Services\ProgramAlerts\Data\WarningCode;
use App\Services\ProgramAlerts\Data\WarningMode;
use App\Services\ProgramAlerts\Data\WarningSeverity;

/**
 * Builds the non-blocking advisories of a projection (DEC-09). It never touches alerts: it only
 * reads contacts and opt-outs (through RecipientDiagnostics).
 *
 * Combination rules: an alert whose roles match nobody yields only ALERT_ROLE_WITHOUT_RECIPIENT;
 * a person without WhatsApp yields RECIPIENT_EMAIL_ONLY (warning) when an e-mail fallback exists
 * and RECIPIENT_WITHOUT_WHATSAPP (critical) when it does not. More than AGGREGATE_ABOVE per-item
 * warnings of one code in one group collapse into a single warning with `count` and `context.items`.
 */
final class ProgramAlertWarningService
{
    public const AGGREGATE_ABOVE = 5;
    public const MAX_AGGREGATED_ITEMS = 50;
    public const MAX_TASKS_WITHOUT_ALERTS_ITEMS = 10;

    public function __construct(private readonly RecipientDiagnostics $diagnostics) {}

    /** @return list<Warning> ordered by severity (critical first), stable inside each severity */
    public function build(
        ProjectionInput $in,
        ProgramProjection $projection,
        WarningMode $mode,
        ExistingAlertIndex $existing,
    ): array {
        $tab = $mode === WarningMode::Tab;
        $warnings = [];

        if ($in->managers->isEmpty()) {
            $warnings[] = new Warning(WarningCode::NoManagersSelected, WarningSeverity::Critical);
        }

        array_push($warnings, ...$this->roleWithoutRecipient($projection, $tab));
        array_push($warnings, ...$this->alertInPast($projection, $existing, $tab));
        array_push($warnings, ...$this->recipientWarnings($projection, $existing, $tab));
        array_push($warnings, ...$this->duplicateTargetDates($in));
        array_push($warnings, ...$this->tasksWithoutAlerts($projection));

        return $this->sorted($warnings);
    }

    /** @return list<Warning> */
    private function roleWithoutRecipient(ProgramProjection $projection, bool $tab): array
    {
        $items = array_filter(
            $projection->items,
            fn (ProjectedAlert $i) => $i->recipients === [] && $i->roles !== [] && (! $tab || ! $i->isPast),
        );

        return $this->perItem(WarningCode::AlertRoleWithoutRecipient, WarningSeverity::Critical, $items, true);
    }

    /** @return list<Warning> */
    private function alertInPast(ProgramProjection $projection, ExistingAlertIndex $existing, bool $tab): array
    {
        $items = array_filter(
            $projection->items,
            fn (ProjectedAlert $i) => $i->recipients !== [] && $i->isPast && ! $existing->has($i),
        );

        return $this->perItem(WarningCode::AlertInPast, $tab ? WarningSeverity::Info : WarningSeverity::Critical, $items, false);
    }

    /**
     * @param array<int, ProjectedAlert> $items
     * @return list<Warning>
     */
    private function perItem(WarningCode $code, WarningSeverity $severity, array $items, bool $withRoles): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$item->targetGuid ?? $item->targetRef][] = $item;
        }

        $warnings = [];

        foreach ($groups as $group) {
            if (count($group) > self::AGGREGATE_ABOVE) {
                $warnings[] = new Warning($code, $severity, $this->targetContext($group[0]) + [
                    'count' => count($group),
                    'items' => array_map(fn (ProjectedAlert $i) => [
                        'protocol_task_guid' => $i->protocolTaskGuid,
                        'protocol_task_alert_guid' => $i->protocolTaskAlertGuid,
                        'scheduled_at_local' => $i->scheduledAtLocal->toArray(),
                    ], array_slice($group, 0, self::MAX_AGGREGATED_ITEMS)),
                ] + ($withRoles ? ['roles' => $group[0]->roles] : []));

                continue;
            }

            foreach ($group as $item) {
                $warnings[] = new Warning($code, $severity, $this->targetContext($item) + [
                    'protocol_task_guid' => $item->protocolTaskGuid,
                    'protocol_task_alert_guid' => $item->protocolTaskAlertGuid,
                    'scheduled_at_local' => $item->scheduledAtLocal->toArray(),
                    'count' => 1,
                ] + ($withRoles ? ['roles' => $item->roles] : []));
            }
        }

        return $warnings;
    }

    /** @return list<Warning> */
    private function recipientWarnings(ProgramProjection $projection, ExistingAlertIndex $existing, bool $tab): array
    {
        $perProfile = [];
        $profiles = collect();

        foreach ($projection->items as $item) {
            $status = $existing->statusFor($item);

            if ($item->recipients === [] || $status === 'dispatched') {
                continue;
            }
            // Still sendable: in the future, or overdue but already pending (the dispatcher will pick it up).
            if ($item->isPast && ($tab || $status !== 'pending')) {
                continue;
            }

            /** @var ProjectedRecipient $recipient */
            foreach ($item->recipients as $recipient) {
                $guid = $recipient->profile->guid;
                $day = $item->scheduledAtLocal->date;

                $profiles->push($recipient->profile);
                $perProfile[$guid]['recipient'] = $recipient;
                $perProfile[$guid]['count'] = ($perProfile[$guid]['count'] ?? 0) + 1;
                $perProfile[$guid]['days'][$day] = ($perProfile[$guid]['days'][$day] ?? 0) + 1;
            }
        }

        if ($perProfile === []) {
            return [];
        }

        $diagnoses = $this->diagnostics->diagnose($profiles);
        $threshold = (int) config('notifications.high_volume_per_day', 3);
        $warnings = [];

        foreach ($perProfile as $guid => $data) {
            /** @var ProjectedRecipient $recipient */
            $recipient = $data['recipient'];
            $diagnosis = $diagnoses[$guid];
            $base = [
                'profile_guid' => $guid,
                'name' => $recipient->profile->user?->name,
                'role' => $recipient->role,
                'count' => $data['count'],
                'email_fallback' => $diagnosis->emailFallback,
            ];

            if ($diagnosis->optedOut) {
                $warnings[] = new Warning(WarningCode::RecipientOptedOut, WarningSeverity::Critical, $base);
            } elseif ($diagnosis->whatsappStatus === 'invalid_number') {
                $warnings[] = new Warning(WarningCode::RecipientInvalidNumber, WarningSeverity::Critical, $base + ['masked' => $diagnosis->masked]);
            } elseif ($diagnosis->whatsappStatus === 'missing') {
                $warnings[] = $diagnosis->emailFallback
                    ? new Warning(WarningCode::RecipientEmailOnly, WarningSeverity::Warning, $base)
                    : new Warning(WarningCode::RecipientWithoutWhatsapp, WarningSeverity::Critical, $base);
            }

            foreach ($data['days'] as $day => $count) {
                if ($count > $threshold) {
                    $warnings[] = new Warning(WarningCode::HighVolumeForRecipient, WarningSeverity::Warning, [
                        'profile_guid' => $guid,
                        'name' => $base['name'],
                        'role' => $recipient->role,
                        'date' => (string) $day,
                        'count' => $count,
                    ]);
                }
            }
        }

        return $warnings;
    }

    /** @return list<Warning> */
    private function duplicateTargetDates(ProjectionInput $in): array
    {
        $byDate = [];
        foreach ($in->targets as $target) {
            $byDate[$target->date][] = $target;
        }

        $warnings = [];
        foreach ($byDate as $date => $targets) {
            if (count($targets) > 1) {
                $warnings[] = new Warning(WarningCode::DuplicateTargetDate, WarningSeverity::Warning, [
                    'date' => (string) $date,
                    'count' => count($targets),
                    'items' => array_map(fn ($t) => $this->guidOrRef($t->guid, $t->ref), $targets),
                ]);
            }
        }

        return $warnings;
    }

    /**
     * One aggregated info warning for the whole program. Context shape:
     * `count` (true total) and `items` (list of {protocol_task_guid, description}, capped at
     * MAX_TASKS_WITHOUT_ALERTS_ITEMS; `count` can exceed `count(items)`).
     *
     * @return list<Warning>
     */
    private function tasksWithoutAlerts(ProgramProjection $projection): array
    {
        $tasks = $projection->tasksWithoutAlerts;

        if ($tasks === []) {
            return [];
        }

        return [new Warning(WarningCode::TaskWithoutAlerts, WarningSeverity::Info, [
            'count' => count($tasks),
            'items' => array_map(fn (array $task) => [
                'protocol_task_guid' => $task['protocolTaskGuid'],
                'description' => $task['description'],
            ], array_slice($tasks, 0, self::MAX_TASKS_WITHOUT_ALERTS_ITEMS)),
        ])];
    }

    /** @return array{target_guid?: string, target_ref?: string} */
    private function targetContext(ProjectedAlert $item): array
    {
        return $this->guidOrRef($item->targetGuid, $item->targetRef);
    }

    /** Draft groups (no persisted guid yet) are identified by their client-side ref. */
    private function guidOrRef(?string $guid, string $ref): array
    {
        return $guid === null || str_starts_with($guid, ProgramAlertPreviewService::DRAFT_PREFIX)
            ? ['target_ref' => $ref]
            : ['target_guid' => $guid];
    }

    /**
     * @param list<Warning> $warnings
     * @return list<Warning>
     */
    private function sorted(array $warnings): array
    {
        $indexed = [];
        foreach ($warnings as $i => $warning) {
            $indexed[] = [$warning->severity->rank(), $i, $warning];
        }
        usort($indexed, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $row) => $row[2], $indexed);
    }
}
