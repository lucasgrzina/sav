<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Gateways\Fake\FakeGateway;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\OptOut;
use App\Notifications\Models\WhatsappWebhookEvent;
use App\Notifications\Pipeline\DeliveryPipeline;
use App\Notifications\Policies\OptOutPolicy;
use App\Notifications\Registries\GatewayRegistry;
use App\Notifications\Registries\MessageBuilderRegistry;
use App\Notifications\Services\ChannelFallbackService;
use App\Notifications\Services\RecordInboundOptOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesNotificationFixtures;
use Tests\TestCase;

class RecordInboundOptOutTest extends TestCase
{
    use CreatesNotificationFixtures, RefreshDatabase;

    private function recorder(): RecordInboundOptOut
    {
        return new RecordInboundOptOut();
    }

    /** @param array<string, mixed>|null $payload */
    private function event(?array $payload): WhatsappWebhookEvent
    {
        return WhatsappWebhookEvent::create([
            'provider' => 'kapso',
            'idempotency_key' => Str::uuid()->toString(),
            'event_type' => 'whatsapp.message.received',
            'payload' => $payload,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function inboundPayload(string $from, string $body): array
    {
        return [
            'type' => 'whatsapp.message.received',
            'message' => [
                'id' => 'wamid.INBOUND',
                'from' => $from,
                'text' => ['body' => $body],
            ],
        ];
    }

    public static function optOutKeywordProvider(): array
    {
        return [
            'baja' => ['baja'],
            'stop' => ['stop'],
            'cancelar' => ['cancelar'],
            'desuscribir' => ['desuscribir'],
            'desuscribirme' => ['desuscribirme'],
        ];
    }

    public static function optInKeywordProvider(): array
    {
        return [
            'alta' => ['alta'],
            'start' => ['start'],
            'suscribir' => ['suscribir'],
            'suscribirme' => ['suscribirme'],
        ];
    }

    #[DataProvider('optOutKeywordProvider')]
    public function test_each_opt_out_keyword_creates_an_opt_out_row(string $keyword): void
    {
        $outcome = $this->recorder()->apply($this->event($this->inboundPayload('5491134290838', $keyword)));

        $this->assertSame('opt-out registrado', $outcome);
        $this->assertDatabaseHas('opt_outs', ['phone' => '5491134290838', 'channel' => Channel::Whatsapp->value]);
    }

    #[DataProvider('optInKeywordProvider')]
    public function test_each_opt_in_keyword_reverses_a_previous_opt_out(string $keyword): void
    {
        OptOut::create(['phone' => '5491134290838', 'channel' => Channel::Whatsapp->value]);

        $outcome = $this->recorder()->apply($this->event($this->inboundPayload('5491134290838', $keyword)));

        $this->assertSame('opt-in: baja revertida', $outcome);
        $this->assertDatabaseMissing('opt_outs', ['phone' => '5491134290838', 'channel' => Channel::Whatsapp->value]);
    }

    public function test_an_opt_in_without_a_previous_opt_out_is_a_no_op(): void
    {
        $outcome = $this->recorder()->apply($this->event($this->inboundPayload('5491134290838', 'ALTA')));

        $this->assertSame('opt-in: no había baja previa', $outcome);
        $this->assertSame(0, OptOut::count());
    }

    /** Case, accents and trailing punctuation must not prevent a match. */
    public function test_keyword_matching_is_case_accent_and_punctuation_insensitive(): void
    {
        foreach (['BAJA', 'baja.', '  Baja  ', 'BAJA¡', 'Bájá'] as $body) {
            OptOut::query()->delete();

            $outcome = $this->recorder()->apply($this->event($this->inboundPayload('5491134290838', $body)));

            $this->assertSame('opt-out registrado', $outcome, "Fallo para el cuerpo: {$body}");
        }
    }

    /**
     * The false positive DEC-07's exact-match design exists to prevent: the word "baja"
     * appears inside free text, but the message is not an opt-out request.
     */
    public function test_free_text_containing_a_keyword_as_a_substring_does_not_opt_anyone_out(): void
    {
        $body = 'ya no quiero mas mensajes de baja de peso del ganado';

        $outcome = $this->recorder()->apply($this->event($this->inboundPayload('5491134290838', $body)));

        $this->assertSame('mensaje entrante sin palabra clave reconocida', $outcome);
        $this->assertSame(0, OptOut::count());
    }

    public function test_unrecognized_free_text_does_not_create_a_row(): void
    {
        $outcome = $this->recorder()->apply(
            $this->event($this->inboundPayload('5491134290838', 'hola, tengo una consulta sobre mi vaca')),
        );

        $this->assertSame('mensaje entrante sin palabra clave reconocida', $outcome);
        $this->assertSame(0, OptOut::count());
    }

    /** Non-text inbound messages (image, sticker, button reply...) carry no text.body. */
    public function test_a_message_without_text_body_is_a_no_op(): void
    {
        $outcome = $this->recorder()->apply($this->event([
            'type' => 'whatsapp.message.received',
            'message' => ['id' => 'wamid.STICKER', 'from' => '5491134290838'],
        ]));

        $this->assertSame('mensaje entrante sin from/body aplicable', $outcome);
        $this->assertSame(0, OptOut::count());
    }

    public function test_a_message_without_a_from_is_a_no_op(): void
    {
        $outcome = $this->recorder()->apply($this->event([
            'type' => 'whatsapp.message.received',
            'message' => ['id' => 'wamid.SINFROM', 'text' => ['body' => 'BAJA']],
        ]));

        $this->assertSame('mensaje entrante sin from/body aplicable', $outcome);
        $this->assertSame(0, OptOut::count());
    }

    /** Repeating the same opt-out is idempotent: firstOrCreate must not violate the unique index. */
    public function test_repeating_the_same_opt_out_does_not_duplicate_the_row_or_throw(): void
    {
        $this->recorder()->apply($this->event($this->inboundPayload('5491134290838', 'BAJA')));
        $outcome = $this->recorder()->apply($this->event($this->inboundPayload('5491134290838', 'baja')));

        $this->assertSame('opt-out registrado', $outcome);
        $this->assertSame(1, OptOut::where('phone', '5491134290838')->where('channel', Channel::Whatsapp->value)->count());
    }

    /** Stub builder decoupled from Alert->subject — the real builders are covered by their own tests. */
    private function builders(): MessageBuilderRegistry
    {
        $builder = new class implements AlertMessageBuilder {
            public function type(): AlertType
            {
                return AlertType::ProgramCreated;
            }

            public function build(Alert $alert, Recipient $recipient): MessageContent
            {
                return new TextContent('hola ' . $recipient->name);
            }
        };

        return new MessageBuilderRegistry([$builder]);
    }

    /**
     * THE FORMAT-SKEW TEST (constraint R4). An opt-out written from an inbound payload whose
     * `message.from` is bare digits must still suppress a recipient whose contact is stored
     * in a differently-formatted spelling of the SAME number. If the inbound normalizer
     * (RecordInboundOptOut → PhoneNumber::normalize) and the outbound normalizer
     * (AlertRecipient::toDto → PhoneNumber::normalize) ever drift apart, this is the only
     * test that catches it.
     */
    public function test_an_opt_out_from_a_bare_digit_inbound_payload_suppresses_a_differently_formatted_contact(): void
    {
        $this->recorder()->apply($this->event($this->inboundPayload('5491134290838', 'BAJA')));

        $profile = $this->createManagerProfile('+54 9 11 3429-0838');
        $alert = $this->createAlert();
        $recipient = $this->createRecipient($profile, $alert, Channel::Whatsapp, DeliveryStatus::Pending);

        (new DeliverAlertJob($recipient->id))->handle(
            $this->builders(),
            new GatewayRegistry(app(), []),
            new DeliveryPipeline([new OptOutPolicy()]),
            new ChannelFallbackService(),
        );

        $this->assertSame(DeliveryStatus::Suppressed, $recipient->refresh()->status);
    }

    /** A suppressed delivery must never trigger the email fallback, including via this new path. */
    public function test_an_opt_out_from_the_inbound_path_does_not_trigger_the_email_fallback(): void
    {
        Queue::fake();

        $this->recorder()->apply($this->event($this->inboundPayload('5491134290838', 'BAJA')));

        $profile = $this->createManagerProfile('5491134290838');
        $alert = $this->createAlert();
        $recipient = $this->createRecipient($profile, $alert, Channel::Whatsapp, DeliveryStatus::Pending);

        (new DeliverAlertJob($recipient->id))->handle(
            $this->builders(),
            new GatewayRegistry(app(), []),
            new DeliveryPipeline([new OptOutPolicy()]),
            new ChannelFallbackService(),
        );

        $this->assertSame(DeliveryStatus::Suppressed, $recipient->refresh()->status);
        $this->assertSame(1, \App\Notifications\Models\AlertRecipient::where('alert_id', $alert->id)->count());
        Queue::assertNothingPushed();
    }
}
