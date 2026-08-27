<?php

namespace App\Notifications\Builders;

use App\Models\Program;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\PushContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;

/**
 * DEC-07: variable 3 (pdf_share_url) no forma parte del body de texto del template — es
 * consumida por el header de medio del template `twilio/media` en Twilio Content Composer.
 */
final class ProgramPdfShareMessageBuilder implements AlertMessageBuilder
{
    public function type(): AlertType
    {
        return AlertType::ProgramPdfShared;
    }

    public function build(Alert $alert, Recipient $recipient): MessageContent
    {
        /** @var Program $program */
        $program = $alert->subject;

        if ($recipient->channel === Channel::Push) {
            return new PushContent(
                title: 'PDF del programa',
                body: "Te compartieron el PDF del programa \"{$program->protocol->name}\".",
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
            type: AlertType::ProgramPdfShared,
            variables: [
                1 => $recipient->name,
                2 => $program->protocol->name,
                3 => $alert->payload['pdf_share_url'],
            ],
        );
    }
}
