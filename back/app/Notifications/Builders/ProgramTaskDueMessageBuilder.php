<?php

namespace App\Notifications\Builders;

use App\Models\Program;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\Payloads\ProgramTaskPayload;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;

final class ProgramTaskDueMessageBuilder implements AlertMessageBuilder
{
    public function type(): AlertType
    {
        return AlertType::ProgramTaskDue;
    }

    public function build(Alert $alert, Recipient $recipient): MessageContent
    {
        /** @var Program $program */
        $program = $alert->subject;
        $payload = ProgramTaskPayload::from($alert->payload);

        if ($recipient->channel === Channel::Email) {
            return new EmailContent(
                subject: "Recordatorio: {$program->protocol->name}",
                body: "Hola {$recipient->name}, {$payload->message}",
            );
        }

        return new TemplateContent(
            type: AlertType::ProgramTaskDue,
            variables: [
                '1' => $recipient->name,
                '2' => $program->protocol->name,
                '3' => $payload->message,
            ],
        );
    }
}
