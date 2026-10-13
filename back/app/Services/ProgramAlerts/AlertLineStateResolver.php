<?php

namespace App\Services\ProgramAlerts;

use App\Notifications\Enums\AlertStatus;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Services\ProgramAlerts\Data\AlertLineState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The single place that turns the delivery attempts of one person on one alert into the state
 * shown to the user. Only real data is reported: `delivered`/`read` need the provider's webhook,
 * a plain `sent` is never presented as delivered.
 *
 * Order of the rules: cancelled alert (or person no longer responsible); any attempt that reached the person (read > delivered > sent,
 * WhatsApp first, then the e-mail fallback); any attempt still pending (scheduled / in process);
 * otherwise every attempt is terminal and the WhatsApp one explains the problem.
 */
final class AlertLineStateResolver
{
    private const REACHED_RANK = [
        DeliveryStatus::Read->value => 3,
        DeliveryStatus::Delivered->value => 2,
        DeliveryStatus::Sent->value => 1,
    ];

    /**
     * @param Collection<int, AlertRecipient> $attemptsOfProfile every non-push attempt of one profile on `$alert`
     */
    public function resolve(Alert $alert, Collection $attemptsOfProfile, CarbonImmutable $now): AlertLineState
    {
        if ($alert->status === AlertStatus::Cancelled->value) {
            return $this->make(AlertLineState::CANCELLED, null, false, $attemptsOfProfile->first());
        }

        // The person stopped being responsible after the alert was created: not a delivery problem.
        if ($attemptsOfProfile->isNotEmpty() && $attemptsOfProfile->every(
            fn (AlertRecipient $a) => $a->status === DeliveryStatus::Suppressed && $a->failure_code === FailureCode::NoLongerApplicable,
        )) {
            return $this->make(AlertLineState::CANCELLED, null, false, $attemptsOfProfile->first());
        }

        $whatsapp = $attemptsOfProfile->filter(fn (AlertRecipient $a) => $a->channel === Channel::Whatsapp)->values();
        $others = $attemptsOfProfile->reject(fn (AlertRecipient $a) => $a->channel === Channel::Whatsapp)->values();

        foreach ([[$whatsapp, false], [$others, true]] as [$group, $fallback]) {
            $best = $this->bestReached($group);

            if ($best !== null) {
                return $this->make($best->status->value, null, $fallback, $best);
            }
        }

        if ($attemptsOfProfile->contains(fn (AlertRecipient $a) => $a->status === DeliveryStatus::Pending)) {
            $state = $alert->status === AlertStatus::Pending->value && $alert->scheduled_at->greaterThan($now)
                ? AlertLineState::SCHEDULED
                : AlertLineState::IN_PROCESS;

            return $this->make($state, null, false, $whatsapp->first() ?? $attemptsOfProfile->first());
        }

        $primary = $whatsapp->first() ?? $attemptsOfProfile->first();

        if ($primary === null) {
            // No attempt rows at all (should not happen for a line): treat as not yet processed.
            return $this->make(AlertLineState::IN_PROCESS, null, false, null);
        }

        if ($primary->status === DeliveryStatus::Failed) {
            return $this->make(AlertLineState::FAILED, $primary->failure_code ?? FailureCode::Unknown, false, $primary);
        }

        return $this->make(AlertLineState::NOT_SENT, $primary->failure_code, false, $primary);
    }

    /** @param Collection<int, AlertRecipient> $attempts */
    private function bestReached(Collection $attempts): ?AlertRecipient
    {
        $best = null;
        $bestRank = 0;

        foreach ($attempts as $attempt) {
            $rank = self::REACHED_RANK[$attempt->status->value] ?? 0;

            if ($rank > $bestRank) {
                $best = $attempt;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    private function make(string $state, ?FailureCode $failureCode, bool $fallbackUsed, ?AlertRecipient $primary): AlertLineState
    {
        return new AlertLineState($state, AlertLineState::bucketFor($state), $failureCode, $fallbackUsed, $primary);
    }
}
