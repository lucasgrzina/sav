<?php

namespace App\Notifications\Templates;

use App\Notifications\Data\Payloads\ProgramTaskPayload;
use App\Notifications\Enums\AlertType;
use App\Notifications\Support\WhatsappVariableSanitizer;
use App\Support\LocalizedDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Single place that words a program.task_due message. The builder (real send) and the
 * program preview both go through compose(), so the previewed text is the text that goes out.
 * v1 is the legacy 3-variable copy; v2 is the 8-variable copy with context and opt-out footer,
 * only used while notifications.templates.program_task_due_v2 is on.
 */
final class TaskDueComposer
{
    public const FALLBACK_NAME = 'equipo';
    /** Meta rejects empty template parameters. */
    public const FALLBACK_EMPTY = '-';
    public const FALLBACK_FREE_TEXT = 'Sin indicaciones adicionales.';

    public function compose(TaskDueContext $ctx, string $recipientName, int $version): ComposedMessage
    {
        $variables = match ($version) {
            1 => [
                1 => $recipientName,
                2 => $ctx->protocolName,
                3 => (string) $ctx->freeText,
            ],
            2 => $this->variablesV2($ctx, $recipientName),
            default => throw new InvalidArgumentException("TaskDueComposer: la versión {$version} del mensaje no existe"),
        };

        return new ComposedMessage(
            version: $version,
            variables: $variables,
            text: WhatsappTemplateCatalog::render(AlertType::ProgramTaskDue, $version, $variables),
        );
    }

    /**
     * Version to use for an alert; `null` means an alert that is about to be created. v2 needs
     * the flag on AND, for a persisted alert, a v2 payload snapshot: legacy alerts keep v1.
     */
    public function effectiveVersion(?ProgramTaskPayload $payload = null): int
    {
        if (! (bool) config('notifications.templates.program_task_due_v2', false)) {
            return 1;
        }

        return $payload === null || $payload->contextVersion === 2 ? 2 : 1;
    }

    /**
     * The "when" line shared by the WhatsApp variable {{7}}, the e-mail and the push:
     * "mañana, jueves 24/09/2026 a las 09:00". Uses the TASK date/time, and the local day of the
     * scheduled send (not "now") for the relative part, so it is stable across retries.
     */
    public function whenText(TaskDueContext $ctx): string
    {
        $taskDate = $ctx->taskDate ?? $ctx->scheduledAtLocal?->toDateString();

        if ($taskDate === null) {
            return '';
        }

        $date = CarbonImmutable::parse($taskDate, 'UTC');
        $text = LocalizedDate::weekday($date, $ctx->locale) . ' ' . $date->format('d/m/Y');

        if ($ctx->scheduledAtLocal !== null) {
            $text = LocalizedDate::relativeDays($date, $ctx->scheduledAtLocal) . ', ' . $text;
        }

        $time = $this->taskTime($ctx->taskTime);

        return $time !== null ? "{$text} a las {$time}" : $text;
    }

    /** @return array<int, string> */
    private function variablesV2(TaskDueContext $ctx, string $recipientName): array
    {
        $protocol = $ctx->protocolName;

        if ($ctx->multiGroup && $ctx->targetDate !== null) {
            $protocol .= ' - grupo del ' . CarbonImmutable::parse($ctx->targetDate, 'UTC')->format('d/m/Y');
        }

        return [
            1 => WhatsappVariableSanitizer::clean($recipientName, 60, self::FALLBACK_NAME),
            2 => WhatsappVariableSanitizer::clean($ctx->vetName, 60, self::FALLBACK_EMPTY),
            3 => WhatsappVariableSanitizer::clean($protocol, 90),
            4 => WhatsappVariableSanitizer::clean($ctx->clientName, 60, self::FALLBACK_EMPTY),
            5 => WhatsappVariableSanitizer::clean($ctx->establishmentName, 60, self::FALLBACK_EMPTY),
            6 => WhatsappVariableSanitizer::clean($ctx->taskDescription, 120, self::FALLBACK_EMPTY),
            7 => WhatsappVariableSanitizer::clean($this->whenText($ctx), 80, self::FALLBACK_EMPTY),
            8 => WhatsappVariableSanitizer::clean($ctx->freeText, 200, self::FALLBACK_FREE_TEXT),
        ];
    }

    private function taskTime(?string $time): ?string
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return null;
        }

        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
    }
}
