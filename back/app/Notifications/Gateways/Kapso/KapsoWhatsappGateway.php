<?php

namespace App\Notifications\Gateways\Kapso;

use App\Notifications\Contracts\NotificationChannelGateway;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\Channel;
use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Exceptions\TemplateNotConfiguredException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Kapso proxies the Meta WhatsApp Cloud API, so both the request bodies and the error
 * envelopes are Meta's. The only Kapso-specific part is the host and the X-API-Key header.
 */
final class KapsoWhatsappGateway implements NotificationChannelGateway
{
    /** failure_reason is a VARCHAR column; Meta error bodies can be far longer than it. */
    private const MAX_FAILURE_REASON = 250;

    /**
     * @param string $messagesEndpoint Absolute URL of the Cloud API messages endpoint for the sending number.
     * @param array<string, array{name?: ?string, language?: ?string}> $templates AlertType value => Meta template ref
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $messagesEndpoint,
        private readonly string $apiKey,
        private readonly array $templates = [],
        private readonly int $timeout = 10,
    ) {
        if ($this->apiKey === '') {
            throw new NotificationConfigurationException('Falta KAPSO_API_KEY.');
        }
    }

    public function channel(): Channel
    {
        return Channel::Whatsapp;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        // A connection failure or timeout raises ConnectionException, which propagates as a
        // transient error — exactly what the queue backoff is for.
        $response = $this->http
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->post($this->messagesEndpoint, $this->payloadFor($message));

        // 5xx and 429 are transient: rethrow so the job retries with backoff. Everything
        // else in the 4xx range (invalid number, template rejected, closed 24h window) is
        // definitive and travels in the DeliveryResult instead.
        if ($response->serverError() || $response->status() === 429) {
            $response->throw();
        }

        if ($response->failed()) {
            return DeliveryResult::failed($this->errorFrom($response));
        }

        $wamid = $response->json('messages.0.id');

        if (! is_string($wamid) || $wamid === '') {
            // A 2xx without a message id means the response contract changed. Treating it
            // as transient is deliberate: marking the recipient Sent with a null
            // provider_message_id would leave it permanently uncorrelatable to its webhooks.
            throw new RuntimeException(
                'Respuesta 2xx de Kapso sin messages.0.id: ' . Str::limit($response->body(), 200),
            );
        }

        return DeliveryResult::sent($wamid);
    }

    /** @return array<string, mixed> */
    private function payloadFor(OutboundMessage $message): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $message->recipient->phone,
        ];

        return match (true) {
            $message->content instanceof TemplateContent => $base + [
                'type' => 'template',
                'template' => $this->templateFor($message->content),
            ],
            $message->content instanceof TextContent => $base + [
                'type' => 'text',
                'text' => ['body' => $message->content->body],
            ],
            default => throw new InvalidArgumentException(
                'Unsupported message content type: ' . get_class($message->content),
            ),
        };
    }

    /** @return array<string, mixed> */
    private function templateFor(TemplateContent $content): array
    {
        $name = $this->templates[$content->type->value]['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new TemplateNotConfiguredException(
                "Sin template de Kapso configurado para {$content->type->value}",
            );
        }

        $template = [
            'name' => $name,
            'language' => ['code' => $this->templates[$content->type->value]['language'] ?? 'es'],
        ];

        // Meta rejects a body component carrying an empty parameter list, so a template
        // without placeholders must omit `components` entirely.
        $parameters = $this->positionalParameters($content->variables);

        if ($parameters !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => $parameters,
            ]];
        }

        return $template;
    }

    /**
     * Meta reads body parameters by position, so TemplateContent's ordinal keys ("1", "2",
     * "3") have to be sorted numerically: a default string sort would place "10" before
     * "2" the first time a template grows past nine placeholders.
     *
     * @param array<int, string> $variables
     * @return list<array{type: string, text: string}>
     */
    private function positionalParameters(array $variables): array
    {
        ksort($variables, SORT_NUMERIC);

        return array_values(array_map(
            fn (string $value): array => ['type' => 'text', 'text' => $value],
            $variables,
        ));
    }

    private function errorFrom(Response $response): string
    {
        $message = $response->json('error.message')
            ?? $response->json('message')
            ?? $response->body();

        $code = $response->json('error.code');

        return Str::limit(
            $code === null ? (string) $message : "[{$code}] {$message}",
            self::MAX_FAILURE_REASON,
        );
    }
}
