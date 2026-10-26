<?php

namespace App\Services\ProgramAlerts;

use App\Models\Client;
use App\Models\Establishment;
use App\Models\Program;
use App\Models\Protocol;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Enums\AlertCancelReason;
use App\Notifications\Models\Alert;
use App\Notifications\Templates\TaskDueComposer;
use App\Notifications\Templates\TaskDueContext;
use App\Services\ProgramAlerts\Data\ExistingAlertIndex;
use App\Services\ProgramAlerts\Data\LocalDateTime;
use App\Services\ProgramAlerts\Data\ProgramPreview;
use App\Services\ProgramAlerts\Data\ProjectedAlert;
use App\Services\ProgramAlerts\Data\ProjectedRecipient;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\Data\RecipientDiagnosis;
use App\Services\ProgramAlerts\Data\TargetInput;
use App\Services\ProgramAlerts\Data\WarningMode;
use Carbon\CarbonImmutable;

/**
 * "What would happen if I saved this?" without saving. It runs the SAME projector, diagnostics,
 * warning rules, composer and (for edits) reconciler planning as the real flow, and never writes
 * or queues anything, so the preview cannot disagree with what gets created.
 */
final class ProgramAlertPreviewService
{
    /** Placeholder guid prefix for groups that do not exist yet, so their logical keys can be computed. */
    public const DRAFT_PREFIX = 'draft-';

    public function __construct(
        private readonly ProgramAlertProjector $projector,
        private readonly ProgramAlertReconciler $reconciler,
        private readonly ProgramAlertWarningService $warnings,
        private readonly RecipientDiagnostics $diagnostics,
        private readonly TaskDueComposer $composer,
    ) {}

    /**
     * Resolves the request (guids, already validated against the tenant) and previews it.
     *
     * @param array<string, mixed> $data client_id, establishment_id, protocol_id (guids),
     *        targets[{guid?, target_ref?, target_date}], manager_profile_ids (guids)
     */
    public function previewDraft(array $data, Vet $vet, ?Program $existing = null): ProgramPreview
    {
        $vet->loadMissing('country');

        $client = Client::where('guid', $data['client_id'])->firstOrFail();
        $establishment = Establishment::where('guid', $data['establishment_id'])->firstOrFail();
        $protocol = Protocol::with('technique', 'tasks.alerts')->where('guid', $data['protocol_id'])->firstOrFail();

        $order = array_flip(array_values($data['manager_profile_ids']));
        $managers = UserProfile::with('role', 'user', 'contacts')
            ->whereIn('guid', array_keys($order))
            ->get()
            ->sortBy(fn (UserProfile $p) => $order[$p->guid])
            ->values();

        $targets = [];
        foreach (array_values($data['targets']) as $index => $target) {
            $targets[] = new TargetInput(
                ref: (string) ($target['target_ref'] ?? $index),
                guid: $target['guid'] ?? null,
                date: CarbonImmutable::parse($target['target_date'])->toDateString(),
                name: $target['name'] ?? null,
                animalCount: isset($target['animals_count']) ? (int) $target['animals_count'] : count($target['animals'] ?? []),
            );
        }

        $in = new ProjectionInput(
            vet: $vet,
            programGuid: $existing?->guid,
            protocol: $protocol,
            targets: $targets,
            managers: $managers,
            now: CarbonImmutable::instance(now()),
            locale: ProjectionInput::localeFor($vet),
            establishmentName: $establishment->name,
        );

        return $this->preview($in, $existing, $client, $establishment);
    }

    public function preview(
        ProjectionInput $in,
        ?Program $existing = null,
        ?Client $client = null,
        ?Establishment $establishment = null,
    ): ProgramPreview {
        if ($existing !== null) {
            $in = $this->withDraftGuids($in);
        }

        $projection = $this->projector->project($in);
        $index = $existing !== null ? ExistingAlertIndex::forProgram($existing) : ExistingAlertIndex::empty();
        $diagnoses = $this->diagnostics->diagnose($in->managers);
        $version = $this->composer->effectiveVersion(null);

        $items = [];
        $sendingProfiles = [];
        $windowDates = [];
        $total = 0;
        $willSend = 0;

        foreach ($projection->items as $item) {
            $row = $this->previewItem($item, $in, $projection->timezone, $index->statusFor($item), $diagnoses, $version, $client, $establishment);
            $items[] = $row;

            if ($item->recipients !== []) {
                $total++;
            }

            if ($row['will_send']) {
                $willSend++;
                $windowDates[] = $item->scheduledAtLocal->date;

                foreach ($row['recipients'] as $recipient) {
                    if ($recipient['will_send']) {
                        $sendingProfiles[$recipient['profile_guid']] = true;
                    }
                }
            }
        }

        $max = (int) config('notifications.preview_max_items', 1000);
        $truncated = count($items) > $max;

        return new ProgramPreview(
            generatedAt: now()->toIso8601String(),
            timezone: $projection->timezone,
            truncated: $truncated,
            templateVersion: $version,
            program: $this->programBlock($in, $client, $establishment),
            summary: [
                'alerts_total' => $total,
                'alerts_will_send' => $willSend,
                'alerts_wont_send' => $total - $willSend,
                'recipients_total' => count($sendingProfiles),
                'groups_count' => count($in->targets),
                'window' => [
                    'from' => $windowDates === [] ? null : min($windowDates),
                    'to' => $windowDates === [] ? null : max($windowDates),
                ],
            ],
            items: $truncated ? array_slice($items, 0, $max) : $items,
            warnings: $this->warnings->build($in, $projection, WarningMode::Preview, $index),
            diff: $existing !== null ? $this->diff($existing, $in) : null,
        );
    }

    /** Groups not saved yet get a placeholder guid so the logical key (and therefore the diff) exists. */
    private function withDraftGuids(ProjectionInput $in): ProjectionInput
    {
        return new ProjectionInput(
            vet: $in->vet,
            programGuid: $in->programGuid,
            protocol: $in->protocol,
            targets: array_map(
                fn (TargetInput $t) => $t->guid === null ? new TargetInput($t->ref, self::DRAFT_PREFIX . $t->ref, $t->date, $t->name, $t->animalCount) : $t,
                $in->targets,
            ),
            managers: $in->managers,
            now: $in->now,
            locale: $in->locale,
            establishmentName: $in->establishmentName,
        );
    }

    /**
     * @param array<string, RecipientDiagnosis> $diagnoses
     * @return array<string, mixed>
     */
    private function previewItem(
        ProjectedAlert $item,
        ProjectionInput $in,
        string $timezone,
        ?string $existingStatus,
        array $diagnoses,
        int $version,
        ?Client $client,
        ?Establishment $establishment,
    ): array {
        $skip = match (true) {
            $item->recipients === [] => 'no_recipients',
            $existingStatus === 'dispatched' => 'already_sent',
            $item->isPast && $existingStatus !== 'pending' => 'in_past',
            default => null,
        };

        $context = new TaskDueContext(
            vetName: (string) $in->vet->name,
            clientName: (string) $client?->name,
            establishmentName: (string) $establishment?->name,
            protocolName: (string) $in->protocol->name,
            multiGroup: $item->multiGroup,
            targetDate: $item->targetDate,
            taskDescription: $item->taskDescription,
            taskDate: $item->taskDate,
            taskTime: $item->taskTime,
            scheduledAtLocal: $item->scheduledAtUtc->setTimezone($timezone),
            freeText: $item->message,
            timezone: $timezone,
            locale: $in->locale,
        );

        $recipients = array_map(
            fn (ProjectedRecipient $r) => $this->previewRecipient($r, $diagnoses[$r->profile->guid], $skip === null, $context, $version),
            $item->recipients,
        );

        $guid = $this->realGuid($item->targetGuid);

        return [
            'target_key' => $guid ?? $item->targetRef,
            'target_guid' => $guid,
            'target_ref' => $item->targetRef,
            'target_date' => $item->targetDate,
            'target_name' => $item->targetName,
            'protocol_task_guid' => $item->protocolTaskGuid,
            'task_description' => $item->taskDescription,
            'task_date' => $item->taskDate,
            'task_time' => $item->taskTime,
            'protocol_task_alert_guid' => $item->protocolTaskAlertGuid,
            'scheduled_at_local' => $item->scheduledAtLocal->toArray(),
            'scheduled_at_utc' => $item->scheduledAtUtc->format('Y-m-d\TH:i:s\Z'),
            'will_send' => $skip === null && collect($recipients)->contains(fn (array $r) => $r['will_send']),
            'skip_reason' => $skip,
            'roles' => $item->roles,
            'require_confirmation' => $item->requireConfirmation, // informational only until V2
            'free_text' => $item->message,
            'recipients' => $recipients,
        ];
    }

    /** @return array<string, mixed> */
    private function previewRecipient(
        ProjectedRecipient $recipient,
        RecipientDiagnosis $diagnosis,
        bool $itemSendable,
        TaskDueContext $context,
        int $version,
    ): array {
        $viaWhatsapp = ! $diagnosis->optedOut && $diagnosis->whatsappStatus === RecipientDiagnosis::OK;
        // Missing or implausible numbers fall back to e-mail at send time; an opt-out never does.
        $viaEmail = ! $diagnosis->optedOut && ! $viaWhatsapp && $diagnosis->emailFallback;
        $contactOk = $viaWhatsapp || $viaEmail;

        $skip = match (true) {
            $contactOk => null,
            $diagnosis->optedOut => 'opted_out',
            $diagnosis->whatsappStatus === RecipientDiagnosis::INVALID_NUMBER => 'invalid_number',
            default => 'no_whatsapp_contact',
        };

        $name = (string) $recipient->profile->user?->name;

        return [
            'profile_guid' => $recipient->profile->guid,
            'name' => $name,
            'role' => $recipient->role,
            'channel' => $viaEmail ? 'email' : 'whatsapp',
            'destination_masked' => $viaEmail ? $diagnosis->emailMasked : $diagnosis->masked,
            'contact_status' => $diagnosis->contactStatus(),
            'will_send' => $itemSendable && $contactOk,
            'skip_reason' => $skip,
            'rendered_message' => $viaWhatsapp ? $this->composer->compose($context, $name !== '' ? $name : 'equipo', $version)->text : null,
        ];
    }

    /** @return array<string, mixed> */
    private function programBlock(ProjectionInput $in, ?Client $client, ?Establishment $establishment): array
    {
        return [
            'company_name' => $in->vet->name,
            'client' => $client !== null ? ['guid' => $client->guid, 'name' => $client->name] : null,
            'establishment' => $establishment !== null ? ['guid' => $establishment->guid, 'name' => $establishment->name] : null,
            'protocol' => ['guid' => $in->protocol->guid, 'name' => $in->protocol->name],
            'technique' => $in->protocol->technique !== null
                ? ['guid' => $in->protocol->technique->guid, 'name' => $in->protocol->technique->name]
                : null,
            'managers' => $in->managers->map(fn (UserProfile $m) => [
                'guid' => $m->guid,
                'name' => (string) $m->user?->name,
                'role' => $m->role?->name,
            ])->values()->all(),
        ];
    }

    /**
     * Edit preview: what the reconciler would do to the persisted alerts if the draft were saved.
     * `rescheduled` is a cancel + create pair for the same group and protocol alert; it is reported
     * once, not as cancelled and created.
     *
     * @return array<string, mixed>
     */
    private function diff(Program $existing, ProjectionInput $in): array
    {
        $existing->loadMissing('protocol');
        $plan = $this->reconciler->planFor($existing, $in);

        $creates = $plan->create;
        $rescheduled = [];
        $cancelledItems = [];

        foreach ($plan->cancel as ['alert' => $alert, 'reason' => $reason]) {
            $payload = $alert->payload ?? [];
            $pairedKey = null;

            if ($reason === AlertCancelReason::Rescheduled) {
                foreach ($creates as $key => $candidate) {
                    if ($candidate->targetGuid === ($payload['programTargetGuid'] ?? null)
                        && $candidate->protocolTaskAlertGuid === ($payload['protocolTaskAlertGuid'] ?? null)) {
                        $pairedKey = $key;
                        break;
                    }
                }
            }

            if ($pairedKey !== null) {
                $rescheduled[] = [
                    'target_guid' => $this->realGuid($payload['programTargetGuid'] ?? null),
                    'protocol_task_alert_guid' => $payload['protocolTaskAlertGuid'] ?? null,
                    'task_description' => $creates[$pairedKey]->taskDescription,
                    'from' => $this->localOf($alert, $in)->toArray(),
                    'to' => $creates[$pairedKey]->scheduledAtLocal->toArray(),
                ];
                unset($creates[$pairedKey]);

                continue;
            }

            $cancelledItems[] = [
                'task_description' => $payload['taskDescription'] ?? null,
                'scheduled_at_local' => $this->localOf($alert, $in)->toArray(),
                'reason' => $reason->value,
            ];
        }

        return [
            'kept' => count($plan->keep) + count($plan->adopt),
            'updated' => count($plan->update),
            'created' => count($creates),
            'cancelled' => count($cancelledItems),
            'rescheduled' => $rescheduled,
            'cancelled_items' => $cancelledItems,
            'sent_not_modified' => array_map(fn (Alert $alert) => [
                'task_description' => $alert->payload['taskDescription'] ?? null,
                'scheduled_at_local' => $this->localOf($alert, $in)->toArray(),
                'target_date_previous' => $alert->payload['targetDate'] ?? null,
                'recipients_count' => $alert->recipients->pluck('user_profile_id')->unique()->count(),
            ], $plan->staleDispatched),
            'protocol_changed' => $existing->protocol?->guid !== $in->protocol->guid,
        ];
    }

    private function localOf(Alert $alert, ProjectionInput $in): LocalDateTime
    {
        return LocalDateTime::from(
            CarbonImmutable::instance($alert->scheduled_at)->setTimezone($in->vet->timezone()),
            $in->locale,
        );
    }

    private function realGuid(?string $guid): ?string
    {
        return $guid === null || str_starts_with($guid, self::DRAFT_PREFIX) ? null : $guid;
    }
}
