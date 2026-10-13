<?php

namespace App\Notifications\Builders;

use App\Models\Program;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\Payloads\ProgramTaskPayload;
use App\Notifications\Data\PushContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;
use App\Notifications\Support\WhatsappVariableSanitizer;
use App\Notifications\Templates\TaskDueComposer;
use App\Notifications\Templates\TaskDueContext;
use App\Services\ProgramAlerts\Data\ProjectionInput;

final class ProgramTaskDueMessageBuilder implements AlertMessageBuilder
{
    private TaskDueComposer $composer;

    public function __construct(?TaskDueComposer $composer = null)
    {
        $this->composer = $composer ?? new TaskDueComposer();
    }

    public function type(): AlertType
    {
        return AlertType::ProgramTaskDue;
    }

    public function build(Alert $alert, Recipient $recipient): MessageContent
    {
        /** @var Program $program */
        $program = $alert->subject;
        $payload = ProgramTaskPayload::from($alert->payload);

        $version = $this->composer->effectiveVersion($payload);

        if ($recipient->channel === Channel::Email) {
            return $version === 2
                ? $this->emailV2($this->context($alert, $program, $payload), $recipient)
                : new EmailContent(
                    subject: "Recordatorio: {$program->protocol->name}",
                    body: "Hola {$recipient->name}, {$payload->message}",
                );
        }

        if ($recipient->channel === Channel::Push) {
            return new PushContent(
                title: 'Recordatorio de tarea',
                body: $version === 2
                    ? $this->pushBodyV2($this->context($alert, $program, $payload))
                    : "{$program->protocol->name}: {$payload->message}",
                tag: "alert-{$alert->guid}",
                url: "programas/{$program->guid}",
                data: [
                    'requires_confirmation' => (bool) $alert->require_confirmation,
                    'alert_guid' => $alert->guid,
                    'program_guid' => $program->guid,
                ],
            );
        }

        $message = $this->composer->compose($this->context($alert, $program, $payload), $recipient->name, $version);

        return new TemplateContent(
            type: AlertType::ProgramTaskDue,
            variables: $message->variables,
            version: $message->version,
        );
    }

    /** Same lines as the WhatsApp v2 message, as a plain-text e-mail. */
    private function emailV2(TaskDueContext $ctx, Recipient $recipient): EmailContent
    {
        $freeText = WhatsappVariableSanitizer::clean($ctx->freeText, 200, TaskDueComposer::FALLBACK_FREE_TEXT);
        $name = WhatsappVariableSanitizer::clean($recipient->name, 60, TaskDueComposer::FALLBACK_NAME);
        $task = WhatsappVariableSanitizer::clean($ctx->taskDescription, 120, TaskDueComposer::FALLBACK_EMPTY);

        return new EmailContent(
            subject: "Recordatorio: {$task} — {$ctx->protocolName}",
            body: implode("\n", [
                "Hola {$name}, te escribe {$ctx->vetName} por el programa \"{$ctx->protocolName}\".",
                "Cliente: {$ctx->clientName}",
                "Establecimiento: {$ctx->establishmentName}",
                "Tarea: {$task}",
                'Cuándo: ' . $this->composer->whenText($ctx),
                "Indicaciones: {$freeText}",
                'Este es un aviso automático de SAV.',
            ]),
        );
    }

    private function pushBodyV2(TaskDueContext $ctx): string
    {
        $task = WhatsappVariableSanitizer::clean($ctx->taskDescription, 120, TaskDueComposer::FALLBACK_EMPTY);

        return "{$ctx->protocolName} · {$ctx->establishmentName}: {$task}, " . $this->composer->whenText($ctx);
    }

    /** Live program data plus the payload snapshot: the same inputs the preview feeds the composer. */
    private function context(Alert $alert, Program $program, ProgramTaskPayload $payload): TaskDueContext
    {
        $timezone = $program->vet?->timezone() ?? config('app.timezone');

        return new TaskDueContext(
            vetName: (string) $program->vet?->name,
            clientName: (string) $program->client?->name,
            establishmentName: (string) $program->establishment?->name,
            protocolName: $program->protocol->name,
            multiGroup: (bool) $payload->multiGroup,
            targetDate: $payload->targetDate,
            taskDescription: $payload->taskDescription,
            taskDate: $payload->taskDate,
            taskTime: $payload->taskTime,
            scheduledAtLocal: $alert->scheduled_at?->copy()->setTimezone($timezone),
            freeText: $payload->message,
            timezone: $timezone,
            locale: $program->vet !== null ? ProjectionInput::localeFor($program->vet) : 'es',
        );
    }
}
