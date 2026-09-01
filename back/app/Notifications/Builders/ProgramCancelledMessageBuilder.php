<?php

namespace App\Notifications\Builders;

use App\Models\Program;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\PushContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;

final class ProgramCancelledMessageBuilder implements AlertMessageBuilder
{
    public function type(): AlertType
    {
        return AlertType::ProgramCancelled;
    }

    public function build(Alert $alert, Recipient $recipient): MessageContent
    {
        /** @var Program $program */
        $program = $alert->subject;

        if ($recipient->channel === Channel::Email) {
            return new EmailContent(
                subject: "Programa cancelado: {$program->protocol->name}",
                body: "Hola {$recipient->name}, se canceló el programa \"{$program->protocol->name}\".",
            );
        }

        if ($recipient->channel === Channel::Push) {
            return new PushContent(
                title: 'Programa cancelado',
                body: "Se canceló el programa \"{$program->protocol->name}\".",
                tag: "alert-{$alert->guid}",
                url: "programas/{$program->guid}",
                data: [
                    'requires_confirmation' => (bool) $alert->require_confirmation,
                    'alert_guid' => $alert->guid,
                    'program_guid' => $program->guid,
                ],
            );
        }

        return new TemplateContent(
            type: AlertType::ProgramCancelled,
            variables: [
                '1' => $recipient->name,
                '2' => $program->protocol->name,
            ],
        );
    }
}
