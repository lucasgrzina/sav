<?php

namespace App\Notifications\Gateways\Twilio;

use App\Notifications\Contracts\NotificationChannelGateway;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Exceptions\TemplateNotConfiguredException;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client;

final class TwilioWhatsappGateway implements NotificationChannelGateway
{
    /** @param array<string,?string> $templates AlertType value => Content API contentSid (HX...) */
    public function __construct(
        private readonly Client $twilio,
        private readonly string $from,
        private readonly array $templates = [],
        private readonly ?string $statusCallbackUrl = null,
    ) {}

    public function channel(): Channel
    {
        return Channel::Whatsapp;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $to = 'whatsapp:+' . $message->recipient->phone;
        $options = ['from' => $this->from];

        if ($this->statusCallbackUrl !== null) {
            $options['statusCallback'] = $this->statusCallbackUrl;
        }

        try {
            $sent = match (true) {
                $message->content instanceof TemplateContent => $this->twilio->messages->create($to, $options + [
                    'contentSid' => $this->resolveContentSid($message->content->type),
                    'contentVariables' => json_encode($message->content->variables),
                ]),
                $message->content instanceof TextContent => $this->twilio->messages->create($to, $options + [
                    'body' => $message->content->body,
                ]),
                default => throw new \InvalidArgumentException('Unsupported message content type: ' . get_class($message->content)),
            };

            return DeliveryResult::sent($sent->sid);
        } catch (RestException $e) {
            if ($e->getStatusCode() >= 400 && $e->getStatusCode() < 500) {
                return DeliveryResult::failed($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Twilio identifies a WhatsApp template by an opaque Content API SID, so the mapping
     * from AlertType lives here and not in the message builders — the builders decide what
     * the message says, each gateway decides how its provider names the template.
     *
     * @throws TemplateNotConfiguredException
     */
    private function resolveContentSid(AlertType $type): string
    {
        return $this->templates[$type->value]
            ?? throw new TemplateNotConfiguredException(
                "Sin contentSid de Twilio configurado para el template {$type->value}",
            );
    }
}
