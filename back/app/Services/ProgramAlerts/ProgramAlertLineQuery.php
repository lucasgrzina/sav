<?php

namespace App\Services\ProgramAlerts;

use App\Models\Program;
use App\Models\UserProfile;
use App\Notifications\Enums\AlertCancelReason;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Services\ProgramAlerts\Data\AlertLineState;
use App\Services\ProgramAlerts\Data\LocalDateTime;
use App\Services\ProgramAlerts\Data\ProgramAlertLine;
use App\Services\ProgramAlerts\Data\ProjectionInput;
use App\Services\ProgramAlerts\Data\RecipientDiagnosis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Lines of the program "Avisos" tab (DEC-07): a light query loads the alerts of the program with
 * their non-push attempts, lines are grouped in memory by (alert, person), the state comes from
 * AlertLineStateResolver (one implementation) and the page is cut here. Counters honour the
 * person / group / type filters but NOT the status filter, so the inner tabs never show 0.
 */
final class ProgramAlertLineQuery
{
    /** Types listed in the tab. */
    public const TYPES = [
        AlertType::ProgramTaskDue,
        AlertType::ProgramCreated,
        AlertType::ProgramCancelled,
        AlertType::ProgramPdfShared,
    ];

    /** Past this many lines a program is logged: the in-memory approach should be revisited. */
    private const LINES_WARNING_THRESHOLD = 5000;

    public function __construct(
        private readonly AlertLineStateResolver $resolver,
        private readonly RecipientDiagnostics $diagnostics,
    ) {}

    /**
     * @param array{status?: ?string, profile?: ?string, target?: ?string, type?: ?string} $filters
     * @return array{lines: list<ProgramAlertLine>, total: int, counters: array<string, int>}
     */
    public function paginate(Program $program, int $vetId, array $filters, int $page, int $perPage, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::instance(now());
        $status = $filters['status'] ?? 'all';

        $entries = [];
        $counters = ['scheduled' => 0, 'sent' => 0, 'problem' => 0, 'cancelled' => 0, 'total' => 0];

        foreach ($this->loadGroups($program, $vetId, $filters) as $group) {
            $state = $this->resolver->resolve($group['alert'], $group['attempts'], $now);
            $counters[$state->bucket]++;
            $counters['total']++;
            $entries[] = $group + ['state' => $state];
        }

        if ($counters['total'] > self::LINES_WARNING_THRESHOLD) {
            Log::warning('program.alerts.lines_over_threshold', ['program_id' => $program->id, 'lines' => $counters['total']]);
        }

        if ($status !== 'all') {
            $entries = array_values(array_filter($entries, fn (array $e) => $e['state']->bucket === $status));
        }

        $this->sort($entries);

        $total = count($entries);
        $pageEntries = array_slice($entries, max(0, ($page - 1) * $perPage), $perPage);

        return [
            'lines' => $this->hydrate($program, $pageEntries, $now),
            'total' => $total,
            'counters' => $counters,
        ];
    }

    /**
     * Every (alert, profile) pair of the program, filtered by person / group / type, with its attempts.
     *
     * @return list<array{alert: Alert, profile_id: int, attempts: Collection<int, AlertRecipient>}>
     */
    private function loadGroups(Program $program, int $vetId, array $filters): array
    {
        $types = ! empty($filters['type'])
            ? [$filters['type']]
            : array_map(fn (AlertType $t) => $t->value, self::TYPES);

        $profileId = null;
        if (! empty($filters['profile'])) {
            $profileId = UserProfile::where('guid', $filters['profile'])->value('id');

            if ($profileId === null) {
                return [];
            }
        }

        // Tenant isolation: vet AND subject type. `subject_id` alone is an internal id shared across morph types.
        $alerts = Alert::query()
            ->where('vet_id', $vetId)
            ->where('subject_type', 'program')
            ->where('subject_id', $program->id)
            ->whereIn('type', $types)
            ->with(['recipients' => function ($query) use ($profileId) {
                $query->where('channel', '!=', Channel::Push->value)
                    ->select([
                        'id', 'guid', 'alert_id', 'user_profile_id', 'channel', 'status', 'failure_code', 'destination_hint',
                        'attempts', 'sent_at', 'delivered_at', 'last_retry_at', 'created_at',
                    ])
                    ->orderBy('id');

                if ($profileId !== null) {
                    $query->where('user_profile_id', $profileId);
                }
            }])
            ->get(['id', 'guid', 'type', 'payload', 'scheduled_at', 'status', 'cancelled_at', 'cancel_reason', 'vet_id']);

        $target = $filters['target'] ?? null;
        $groups = [];

        foreach ($alerts as $alert) {
            if ($target !== null && ($alert->payload['programTargetGuid'] ?? null) !== $target) {
                continue;
            }

            foreach ($alert->recipients->groupBy('user_profile_id') as $pid => $attempts) {
                $groups[] = ['alert' => $alert, 'profile_id' => (int) $pid, 'attempts' => $attempts->values()];
            }
        }

        return $groups;
    }

    /** Scheduled lines first (soonest first), then everything else (newest first). */
    private function sort(array &$entries): void
    {
        usort($entries, function (array $a, array $b) {
            $aScheduled = $a['state']->bucket === AlertLineState::BUCKET_SCHEDULED;
            $bScheduled = $b['state']->bucket === AlertLineState::BUCKET_SCHEDULED;

            if ($aScheduled !== $bScheduled) {
                return $aScheduled ? -1 : 1;
            }

            $byDate = $a['alert']->scheduled_at->getTimestamp() <=> $b['alert']->scheduled_at->getTimestamp();
            $byDate = $aScheduled ? $byDate : -$byDate;

            return $byDate !== 0
                ? $byDate
                : [$a['alert']->id, $a['profile_id']] <=> [$b['alert']->id, $b['profile_id']];
        });
    }

    /**
     * Loads people, live contact status, retry advice and message bodies only for the page.
     *
     * @param list<array{alert: Alert, profile_id: int, attempts: Collection<int, AlertRecipient>, state: AlertLineState}> $entries
     * @return list<ProgramAlertLine>
     */
    private function hydrate(Program $program, array $entries, CarbonImmutable $now): array
    {
        if ($entries === []) {
            return [];
        }

        $program->loadMissing('vet.country');
        $timezone = $program->vet->timezone();
        $locale = ProjectionInput::localeFor($program->vet);

        $profiles = UserProfile::with(['user', 'role', 'contacts'])
            ->whereIn('id', array_unique(array_column($entries, 'profile_id')))
            ->get()
            ->keyBy('id');

        // Live contact status is only useful where the user can still act on it.
        $needsContact = array_filter($entries, fn (array $e) => in_array($e['state']->bucket, [
            AlertLineState::BUCKET_PROBLEM, AlertLineState::BUCKET_SCHEDULED,
        ], true));
        $diagnoses = $needsContact === []
            ? []
            : $this->diagnostics->diagnose(
                collect($needsContact)->map(fn (array $e) => $profiles->get($e['profile_id']))->filter()->values(),
            );

        $primaryIds = array_values(array_filter(array_map(fn (array $e) => $e['state']->primary?->id, $entries)));
        $bodies = $primaryIds === []
            ? collect()
            : AlertRecipient::whereIn('id', $primaryIds)->pluck('rendered_body', 'id');

        $lines = [];

        foreach ($entries as $entry) {
            /** @var Alert $alert */
            $alert = $entry['alert'];
            /** @var AlertLineState $state */
            $state = $entry['state'];
            $profile = $profiles->get($entry['profile_id']);

            if ($profile === null) {
                continue;
            }

            $diagnosis = $diagnoses[$profile->guid] ?? null;
            $local = CarbonImmutable::instance($alert->scheduled_at)->setTimezone($timezone);

            $lines[] = new ProgramAlertLine(
                alert: $alert,
                profile: $profile,
                attempts: $entry['attempts'],
                state: $state,
                scheduledAtLocal: LocalDateTime::from($local, $locale),
                cancelReason: $this->cancelReason($alert, $state),
                contact: $diagnosis,
                retry: $state->bucket === AlertLineState::BUCKET_PROBLEM
                    ? $this->retryAdvice($program, $state, $diagnosis, $now)
                    : null,
                renderedBody: $state->primary !== null ? $bodies->get($state->primary->id) : null,
            );
        }

        return $lines;
    }

    private function cancelReason(Alert $alert, AlertLineState $state): ?string
    {
        if ($state->state !== AlertLineState::CANCELLED) {
            return null;
        }

        return ($alert->cancel_reason ?? AlertCancelReason::RecipientRemoved)->value;
    }

    /**
     * Informative "can this be resent" for the button. The authoritative checks (locking, opt-out,
     * siblings, cooldown) are re-run by the resend action itself.
     *
     * @return array{allowed: bool, recipient_guid: ?string, blocked_reason: ?string}
     */
    private function retryAdvice(Program $program, AlertLineState $state, ?RecipientDiagnosis $diagnosis, CarbonImmutable $now): array
    {
        $primary = $state->primary;
        $blocked = fn (string $reason) => ['allowed' => false, 'recipient_guid' => $primary?->guid, 'blocked_reason' => $reason];

        if ($program->cancelled_at !== null) {
            return $blocked('program_cancelled');
        }

        $code = $state->failureCode ?? $primary?->failure_code;

        if ($primary === null || ! ProgramAlertRetryService::isEligibleAttempt($primary)) {
            return $blocked('not_retryable');
        }

        if ($diagnosis?->optedOut === true) {
            return $blocked('opted_out');
        }

        if ($primary->last_retry_at !== null && $primary->last_retry_at->greaterThan($now->subMinutes(ProgramAlertRetryService::COOLDOWN_MINUTES))) {
            return $blocked('recently_retried');
        }

        if (in_array($code, [FailureCode::NoWhatsappContact, FailureCode::InvalidNumber], true)
            && $diagnosis !== null && $diagnosis->whatsappStatus !== RecipientDiagnosis::OK) {
            return $blocked('contact_still_missing');
        }

        return ['allowed' => true, 'recipient_guid' => $primary->guid, 'blocked_reason' => null];
    }
}
