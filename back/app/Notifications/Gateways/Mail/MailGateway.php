<?php

namespace App\Notifications\Gateways\Mail;

use App\Mail\AlertMail;
use App\Notifications\Contracts\NotificationChannelGateway;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Enums\Channel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class MailGateway implements NotificationChannelGateway
{
    public function channel(): Channel
    {
        return Channel::Email;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        if (!$message->content instanceof EmailContent) {
            throw new InvalidArgumentException('MailGateway solo puede enviar EmailContent, recibió ' . get_class($message->content));
        }

        if ($message->recipient->email === null) {
            return DeliveryResult::failed('El destinatario no tiene un email configurado.');
        }

        // Mail::send() no distingue fallos definitivos (dirección inválida) de transitorios
        // (SMTP caído): cualquier excepción se relanza para que la cola reintente con backoff.
        Mail::to($message->recipient->email)->send(
            new AlertMail($message->content->subject, $message->content->body),
        );

        return DeliveryResult::sent(Str::uuid()->toString());
    }
}
