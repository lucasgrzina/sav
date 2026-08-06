<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Exceptions\TemplateNotConfiguredException;
use App\Notifications\Gateways\Kapso\KapsoWhatsappGateway;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class KapsoWhatsappGatewayTest extends TestCase
{
    private const ENDPOINT = 'https://api.kapso.test/meta/whatsapp/v24.0/PN123/messages';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @param array<string, array{name?: ?string, language?: ?string}> $templates */
    private function gateway(?array $templates = null, string $apiKey = 'kapso-key'): KapsoWhatsappGateway
    {
        return new KapsoWhatsappGateway(
            app(Factory::class),
            self::ENDPOINT,
            $apiKey,
            $templates ?? [
                'program.created' => ['name' => 'sav_program_created', 'language' => 'es'],
            ],
        );
    }

    private function recipient(): Recipient
    {
        return new Recipient(
            userId: 1,
            phone: '5491122334455',
            name: 'Juan',
            channel: Channel::Whatsapp,
        );
    }

    private function message(mixed $content): OutboundMessage
    {
        return new OutboundMessage(
            recipient: $this->recipient(),
            content: $content,
            channel: Channel::Whatsapp,
            idempotencyKey: 'key-1',
        );
    }

    private function fakeAccepted(string $wamid = 'wamid.ABC'): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '5491122334455', 'wa_id' => '5491122334455']],
                'messages' => [['id' => $wamid]],
            ], 200),
        ]);
    }

    public function test_sends_a_template_as_a_meta_cloud_api_payload(): void
    {
        $this->fakeAccepted('wamid.HBgNMTU1NTE0OTU5Nzg1');

        $result = $this->gateway()->send($this->message(
            new TemplateContent(AlertType::ProgramCreated, ['1' => 'Juan', '2' => 'Plan Vacunación']),
        ));

        $this->assertSame(DeliveryStatus::Sent, $result->status);
        $this->assertSame('wamid.HBgNMTU1NTE0OTU5Nzg1', $result->providerMessageId);

        Http::assertSent(function (Request $request) {
            return $request->url() === self::ENDPOINT
                && $request->hasHeader('X-API-Key', 'kapso-key')
                && $request->data() === [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => '5491122334455',
                    'type' => 'template',
                    'template' => [
                        'name' => 'sav_program_created',
                        'language' => ['code' => 'es'],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => 'Juan'],
                                ['type' => 'text', 'text' => 'Plan Vacunación'],
                            ],
                        ]],
                    ],
                ];
        });
    }

    public function test_sends_free_form_text_as_a_text_message(): void
    {
        $this->fakeAccepted();

        $result = $this->gateway()->send($this->message(new TextContent('hola')));

        $this->assertSame(DeliveryStatus::Sent, $result->status);

        Http::assertSent(fn (Request $request) => $request->data() === [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '5491122334455',
            'type' => 'text',
            'text' => ['body' => 'hola'],
        ]);
    }

    /** Regression: a plain string sort would put "10" between "1" and "2". */
    public function test_orders_body_parameters_numerically_past_nine_placeholders(): void
    {
        $this->fakeAccepted();

        $variables = [];
        foreach (range(1, 11) as $position) {
            $variables[(string) $position] = "v{$position}";
        }

        $this->gateway()->send($this->message(
            new TemplateContent(AlertType::ProgramCreated, $variables),
        ));

        Http::assertSent(function (Request $request) {
            $texts = array_column($request->data()['template']['components'][0]['parameters'], 'text');

            return $texts === ['v1', 'v2', 'v3', 'v4', 'v5', 'v6', 'v7', 'v8', 'v9', 'v10', 'v11'];
        });
    }

    /** Meta rejects a body component with an empty parameter list. */
    public function test_omits_components_for_a_template_without_placeholders(): void
    {
        $this->fakeAccepted();

        $this->gateway()->send($this->message(
            new TemplateContent(AlertType::ProgramCreated, []),
        ));

        Http::assertSent(fn (Request $request) => $request->data()['template'] === [
            'name' => 'sav_program_created',
            'language' => ['code' => 'es'],
        ]);
    }

    public function test_defaults_the_language_code_when_the_template_omits_it(): void
    {
        $this->fakeAccepted();

        $gateway = $this->gateway(['program.created' => ['name' => 'sav_program_created']]);
        $gateway->send($this->message(new TemplateContent(AlertType::ProgramCreated, ['1' => 'Juan'])));

        Http::assertSent(fn (Request $request) => $request->data()['template']['language'] === ['code' => 'es']);
    }

    public function test_a_4xx_becomes_a_failed_result_carrying_the_meta_error(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'error' => [
                    'message' => 'Message failed to send because more than 24 hours have passed',
                    'type' => 'OAuthException',
                    'code' => 131047,
                ],
            ], 400),
        ]);

        $result = $this->gateway()->send($this->message(new TextContent('hola')));

        $this->assertSame(DeliveryStatus::Failed, $result->status);
        $this->assertStringContainsString('131047', $result->failureReason);
        $this->assertStringContainsString('24 hours', $result->failureReason);
    }

    public function test_a_failure_reason_is_truncated_to_fit_the_column(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['error' => ['message' => str_repeat('x', 5000)]], 400),
        ]);

        $result = $this->gateway()->send($this->message(new TextContent('hola')));

        $this->assertSame(DeliveryStatus::Failed, $result->status);
        $this->assertLessThanOrEqual(255, strlen($result->failureReason));
    }

    public function test_a_5xx_is_rethrown_so_the_queue_applies_backoff(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['message' => 'oops']], 503)]);

        $this->expectException(RequestException::class);

        $this->gateway()->send($this->message(new TextContent('hola')));
    }

    public function test_a_429_is_rethrown_as_transient(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        $this->expectException(RequestException::class);

        $this->gateway()->send($this->message(new TextContent('hola')));
    }

    /** A 2xx with no id would leave the recipient Sent but uncorrelatable to its webhooks. */
    public function test_a_2xx_without_a_message_id_is_treated_as_transient(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['messaging_product' => 'whatsapp'], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sin messages\.0\.id/');

        $this->gateway()->send($this->message(new TextContent('hola')));
    }

    public function test_an_unmapped_template_throws_a_configuration_error_without_calling_the_api(): void
    {
        Http::fake();

        $this->expectException(TemplateNotConfiguredException::class);

        try {
            $this->gateway()->send($this->message(
                new TemplateContent(AlertType::ProgramCancelled, ['1' => 'Juan']),
            ));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_blank_template_name_is_a_configuration_error(): void
    {
        Http::fake();

        $this->expectException(TemplateNotConfiguredException::class);

        $this->gateway(['program.created' => ['name' => '']])
            ->send($this->message(new TemplateContent(AlertType::ProgramCreated, ['1' => 'Juan'])));
    }

    public function test_a_missing_api_key_is_a_configuration_error(): void
    {
        $this->expectException(NotificationConfigurationException::class);

        $this->gateway(apiKey: '');
    }

    public function test_the_configuration_error_is_definitive_so_the_job_does_not_retry(): void
    {
        // TemplateNotConfiguredException must remain catchable as the generic configuration
        // error, which is what DeliverAlertJob keys off to skip its backoff.
        $this->assertInstanceOf(
            NotificationConfigurationException::class,
            new TemplateNotConfiguredException('x'),
        );
    }
}
