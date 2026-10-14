<?php

namespace App\Notifications\Gateways\Twilio;

use App\Notifications\Contracts\NotificationChannelGateway;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Exceptions\TemplateNotConfiguredException;
use App\Notifications\Services\FailureClassifier;
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
        private readonly ?FailureClassifier $classifier = null,
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

        $unreachableMediaUrl = $this->unreachableMediaUrl($message);

        if ($unreachableMediaUrl !== null) {
            return DeliveryResult::failed(
                "Media URL is not publicly reachable ({$unreachableMediaUrl}); Twilio rejects it as an invalid Content Variable. Check APP_URL.",
                FailureCode::ServiceMisconfigured,
            );
        }

        try {
            $sent = match (true) {
                $message->content instanceof TemplateContent => $this->twilio->messages->create($to, $options + [
                    'contentSid' => $this->resolveContentSid($message->content->templateKey()),
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
                return DeliveryResult::failed(
                    $e->getMessage(),
                    ($this->classifier ?? new FailureClassifier())->fromProviderCode(
                        FailureClassifier::PROVIDER_TWILIO,
                        $e->getCode(),
                    ),
                );
            }

            throw $e;
        }
    }

    /**
     * The PDF share template carries its download URL as the media variable ({{3}}). Twilio
     * fetches it from the internet, so a local host (APP_URL=http://localhost) is rejected
     * with a cryptic 400 "Content Variables parameter is invalid" — fail early and clearly.
     */
    private function unreachableMediaUrl(OutboundMessage $message): ?string
    {
        if (!$message->content instanceof TemplateContent || $message->content->type !== AlertType::ProgramPdfShared) {
            return null;
        }

        $url = (string) ($message->content->variables[3] ?? '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $isLocal = $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);

        return $isLocal ? $url : null;
    }

    /**
     * Twilio identifies a WhatsApp template by an opaque Content API SID, so the mapping
     * from AlertType lives here and not in the message builders — the builders decide what
     * the message says, each gateway decides how its provider names the template.
     *
     * @throws TemplateNotConfiguredException
     */
    private function resolveContentSid(string $templateKey): string
    {
        $sid = $this->templates[$templateKey] ?? null;

        if (! is_string($sid) || $sid === '') {
            throw new TemplateNotConfiguredException(
                "Sin contentSid de Twilio configurado para el template {$templateKey}",
            );
        }

        return $sid;
    }
}
