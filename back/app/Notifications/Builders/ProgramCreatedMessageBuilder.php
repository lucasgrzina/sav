<?php

namespace App\Notifications\Builders;

use App\Models\Program;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;

final class ProgramCreatedMessageBuilder implements AlertMessageBuilder
{
    public function type(): AlertType
    {
        return AlertType::ProgramCreated;
    }

    public function build(Alert $alert, Recipient $recipient): MessageContent
    {
        /** @var Program $program */
        $program = $alert->subject;

        if ($recipient->channel === Channel::Email) {
            return new EmailContent(
                subject: "Programa creado: {$program->protocol->name}",
                body: "Hola {$recipient->name}, se creó el programa \"{$program->protocol->name}\".",
            );
        }

        return new TemplateContent(
            type: AlertType::ProgramCreated,
            variables: [
                '1' => $recipient->name,
                '2' => $program->protocol->name,
            ],
        );
    }
}
