<?php

namespace App\Http\Resources\V1;

use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\AlertRecipient;
use App\Services\ProgramAlerts\Data\AlertLineState;
use App\Services\ProgramAlerts\Data\ProgramAlertLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ProgramAlertLine $resource
 *
 * Presentation only. Never exposes internal ids, `failure_reason`, `confirmed_at`, provider data or
 * unmasked contact data: destinations come from the masked hint stored at send time.
 */
class ProgramAlertLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProgramAlertLine $line */
        $line = $this->resource;
        $alert = $line->alert;
        $state = $line->state;
        $primary = $state->primary;
        $payload = $alert->payload ?? [];

        $emailAttempt = $line->attempts->first(fn (AlertRecipient $a) => $a->channel === Channel::Email);
        $reached = in_array($state->state, [AlertLineState::SENT, AlertLineState::DELIVERED, AlertLineState::READ], true);

        return [
            'key'                => $alert->guid . ':' . $line->profile->guid,
            'alert_guid'         => $alert->guid,
            'type'               => $alert->type->value,
            'scheduled_at_local' => $line->scheduledAtLocal->toArray(),
            'scheduled_at_utc'   => $alert->scheduled_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'recipient'          => [
                'profile_guid' => $line->profile->guid,
                'name'         => $line->profile->user?->name,
                'role'         => $line->profile->role?->name,
            ],
            'channel'            => $primary === null || $primary->channel === Channel::Whatsapp ? 'whatsapp' : 'email_fallback',
            'state'              => $state->state,
            'bucket'             => $state->bucket,
            'failure_code'       => $state->failureCode?->value,
            'cancel_reason'      => $line->cancelReason,
            'fallback'           => [
                'used'        => $state->fallbackUsed,
                'email_state' => $emailAttempt?->status->value,
            ],
            'destination_masked' => $primary?->destination_hint,
            'sent_at'            => $reached ? $primary?->sent_at?->toISOString() : null,
            'delivered_at'       => in_array($state->state, [AlertLineState::DELIVERED, AlertLineState::READ], true)
                ? $primary?->delivered_at?->toISOString()
                : null,
            'task'               => $this->task($payload),
            'message'            => $payload['message'] ?? null,
            'rendered_body'      => $line->renderedBody,
            'group'              => $this->group($payload),
            'contact'            => $line->contact === null ? null : [
                'status' => $line->contact->contactStatus(),
                'masked' => $line->contact->masked,
            ],
            'attempts'           => $line->attempts->map(fn (AlertRecipient $a) => [
                'recipient_guid'     => $a->guid,
                'channel'            => $a->channel->value,
                'state'              => $a->status->value,
                'failure_code'       => ($a->status === DeliveryStatus::Failed ? ($a->failure_code ?? FailureCode::Unknown) : $a->failure_code)?->value,
                'destination_masked' => $a->destination_hint,
                'sent_at'            => $a->sent_at?->toISOString(),
                'delivered_at'       => $a->delivered_at?->toISOString(),
                'attempts'           => (int) $a->attempts,
            ])->values(),
            'retry'              => $line->retry,
        ];
    }

    /** @return array<string, mixed>|null null for legacy payloads and for non-task alerts */
    private function task(array $payload): ?array
    {
        if (empty($payload['taskDescription']) && empty($payload['protocolTaskGuid'])) {
            return null;
        }

        return [
            'protocol_task_guid' => $payload['protocolTaskGuid'] ?? null,
            'description'        => $payload['taskDescription'] ?? null,
            'task_date'          => $payload['taskDate'] ?? null,
            'task_time'          => $payload['taskTime'] ?? null,
        ];
    }

    /** Built from the payload: the group may have been deleted since. */
    private function group(array $payload): ?array
    {
        $targetDate = $payload['targetDate'] ?? null;

        if (empty($payload['programTargetGuid']) && $targetDate === null) {
            return null;
        }

        return [
            'target_guid' => $payload['programTargetGuid'] ?? null,
            'target_date' => $targetDate,
            'label'       => $targetDate !== null ? 'grupo del ' . date('d/m/Y', strtotime($targetDate)) : null,
        ];
    }
}
