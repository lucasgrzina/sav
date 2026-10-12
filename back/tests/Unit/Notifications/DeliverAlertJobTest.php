<?php

namespace Tests\Unit\Notifications;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\PushContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\SuppressionReason;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Gateways\Fake\FakeGateway;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Pipeline\DeliveryPipeline;
use App\Notifications\Policies\OptOutPolicy;
use App\Notifications\Registries\GatewayRegistry;
use App\Notifications\Registries\MessageBuilderRegistry;
use App\Notifications\Services\ChannelFallbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverAlertJobTest extends TestCase
{
    use RefreshDatabase;

    private function createManagerProfile(): UserProfile
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Argentina',
            'iso_code' => 'A' . Str::random(4),
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(),
            'country_id' => $country->id,
            'name' => 'CUIT',
            'validation_regex' => '.*',
        ]);

        $vet = Vet::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Vet Test',
            'slug' => 'vet-test-' . Str::random(6),
            'country_id' => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id' => '20-12345678-9',
        ]);

        $role = Role::create(['name' => 'vet_vet', 'guard_name' => 'web', 'type' => Role::TYPE_TENANT]);
        $user = User::factory()->create();

        $profile = UserProfile::create([
            'user_id' => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $vet->id,
            'role_id' => $role->id,
        ]);

        Contact::create([
            'contactable_type' => 'user_profile',
            'contactable_id' => $profile->id,
            'type' => ContactType::Whatsapp,
            'value' => '5491122334455',
            'is_primary' => true,
            'use_for_alerts' => true,
        ]);

        return $profile;
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

    /** Stub builder that always returns null — simula HealthPlanMonthMessageBuilder cuando ya no queda nada pendiente (RF-02). */
    private function nullBuilders(): MessageBuilderRegistry
    {
        $builder = new class implements AlertMessageBuilder {
            public function type(): AlertType
            {
                return AlertType::ProgramCreated;
            }

            public function build(Alert $alert, Recipient $recipient): ?MessageContent
            {
                return null;
            }
        };

        return new MessageBuilderRegistry([$builder]);
    }

    public function test_delivers_a_pending_recipient_and_marks_it_sent(): void
    {
        $profile = $this->createManagerProfile();

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $fakeGateway = new FakeGateway();
        $fakeGateway->willReturn(DeliveryResult::sent('SM999'));
        app()->instance(FakeGateway::class, $fakeGateway);

        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        $job = new DeliverAlertJob($recipient->id);
        $job->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Sent, $recipient->status);
        $this->assertSame('SM999', $recipient->provider_message_id);
        $this->assertSame(1, $recipient->attempts);
        $this->assertCount(1, $fakeGateway->sentMessages());
    }

    public function test_skips_recipients_that_are_no_longer_pending(): void
    {
        $profile = $this->createManagerProfile();
        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Sent,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $job = new DeliverAlertJob($recipient->id);
        $job->handle(
            $this->builders(),
            new GatewayRegistry(app(), []),
            new DeliveryPipeline([new OptOutPolicy()]),
            new ChannelFallbackService(),
        );

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Sent, $recipient->status);
        $this->assertSame(0, $recipient->attempts);
    }

    public function test_marks_failed_when_recipient_has_no_alert_contact(): void
    {
        $profile = $this->createManagerProfile();
        $profile->contacts()->update(['use_for_alerts' => false]);

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $job = new DeliverAlertJob($recipient->id);
        $job->handle(
            $this->builders(),
            new GatewayRegistry(app(), []),
            new DeliveryPipeline([]),
            new ChannelFallbackService(),
        );

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);
        $this->assertNotNull($recipient->failure_reason);
    }

    public function test_suppresses_via_pipeline_without_calling_the_gateway(): void
    {
        $profile = $this->createManagerProfile();

        \App\Notifications\Models\OptOut::create([
            'phone' => '5491122334455',
            'channel' => Channel::Whatsapp,
        ]);

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $job = new DeliverAlertJob($recipient->id);
        $job->handle(
            $this->builders(),
            new GatewayRegistry(app(), []),
            new DeliveryPipeline([new OptOutPolicy()]),
            new ChannelFallbackService(),
        );

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Suppressed, $recipient->status);
        $this->assertSame(SuppressionReason::OptedOut->value, $recipient->failure_reason);
    }

    public function test_a_definitive_gateway_failure_falls_back_to_email(): void
    {
        Queue::fake();

        $profile = $this->createManagerProfile();

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $fakeGateway = new FakeGateway();
        $fakeGateway->willReturn(DeliveryResult::failed('numero invalido'));
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        (new DeliverAlertJob($recipient->id))->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);

        $fallback = AlertRecipient::where('alert_id', $alert->id)
            ->where('channel', Channel::Email)
            ->firstOrFail();
        $this->assertSame(DeliveryStatus::Pending, $fallback->status);

        Queue::assertPushed(DeliverAlertJob::class, fn ($job) => $job->recipientId === $fallback->id);
    }

    public function test_suppression_never_triggers_a_fallback(): void
    {
        Queue::fake();

        $profile = $this->createManagerProfile();
        \App\Notifications\Models\OptOut::create(['phone' => '5491122334455', 'channel' => Channel::Whatsapp]);

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        (new DeliverAlertJob($recipient->id))->handle(
            $this->builders(),
            new GatewayRegistry(app(), []),
            new DeliveryPipeline([new OptOutPolicy()]),
            new ChannelFallbackService(),
        );

        $this->assertSame(1, AlertRecipient::where('alert_id', $alert->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_does_not_duplicate_a_fallback_channel_already_attempted(): void
    {
        Queue::fake();

        $profile = $this->createManagerProfile();
        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        // An email attempt for this alert+profile already exists (e.g. from a prior failure).
        AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Email,
            'status' => DeliveryStatus::Sent,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $fakeGateway = new FakeGateway();
        $fakeGateway->willReturn(DeliveryResult::failed('numero invalido'));
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        (new DeliverAlertJob($recipient->id))->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $this->assertSame(2, AlertRecipient::where('alert_id', $alert->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_a_config_time_gateway_failure_falls_back_to_email_without_throwing(): void
    {
        Queue::fake();
        Log::spy();

        $profile = $this->createManagerProfile();

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // WHATSAPP_PROVIDER inválido: la clave whatsapp existe pero resuelve a un gateway null.
        $gateways = new GatewayRegistry(app(), [
            'whatsapp' => ['provider' => 'bogus', 'gateway' => null, 'available' => ['twilio', 'kapso', 'fake']],
        ]);

        // No debe lanzar: es justamente la garantía que evita que la cola reintente un
        // error de configuración durante 5 intentos / ~50 minutos.
        (new DeliverAlertJob($recipient->id))->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);
        $this->assertNotNull($recipient->failure_reason);

        $fallback = AlertRecipient::where('alert_id', $alert->id)
            ->where('channel', Channel::Email)
            ->firstOrFail();
        $this->assertSame(DeliveryStatus::Pending, $fallback->status);

        Queue::assertPushed(DeliverAlertJob::class, fn ($job) => $job->recipientId === $fallback->id);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_a_channel_absent_from_config_falls_back_to_email_without_throwing(): void
    {
        Queue::fake();

        $profile = $this->createManagerProfile();

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // whatsapp ni siquiera está declarado en el config: también es definitivo desde GAP 2.
        $gateways = new GatewayRegistry(app(), []);

        (new DeliverAlertJob($recipient->id))->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);
        $this->assertNotNull($recipient->failure_reason);

        $fallback = AlertRecipient::where('alert_id', $alert->id)
            ->where('channel', Channel::Email)
            ->firstOrFail();
        $this->assertSame(DeliveryStatus::Pending, $fallback->status);

        Queue::assertPushed(DeliverAlertJob::class, fn ($job) => $job->recipientId === $fallback->id);
    }

    public function test_failed_hook_marks_recipient_failed_and_triggers_fallback_after_retries_exhausted(): void
    {
        Queue::fake();

        $profile = $this->createManagerProfile();
        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        (new DeliverAlertJob($recipient->id))->failed(new \RuntimeException('Twilio timeout'));

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);
        $this->assertSame('Twilio timeout', $recipient->failure_reason);

        $fallback = AlertRecipient::where('alert_id', $alert->id)->where('channel', Channel::Email)->firstOrFail();
        Queue::assertPushed(DeliverAlertJob::class, fn ($job) => $job->recipientId === $fallback->id);
    }

    /**
     * DEC2-02 / RF-02: un builder que recalcula el contenido en destino y determina que la
     * alerta ya no aplica (ej. HealthPlanMonthMessageBuilder con todas las actividades
     * confirmadas) retorna null. DeliverAlertJob debe tratarlo como Suppressed, sin llamar
     * al gateway ni intentar fallback — no es un fallo técnico, es una decisión de contenido.
     */
    public function test_a_null_content_from_the_builder_suppresses_the_recipient_without_calling_the_gateway(): void
    {
        Queue::fake();

        $profile = $this->createManagerProfile();

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $fakeGateway = new FakeGateway();
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        $job = new DeliverAlertJob($recipient->id);
        $job->handle($this->nullBuilders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Suppressed, $recipient->status);
        $this->assertSame('no_longer_applicable', $recipient->failure_reason);
        $this->assertCount(0, $fakeGateway->sentMessages());
        Queue::assertNothingPushed();
    }

    /** End-to-end con FakeGateway(Channel::Push): el flujo builder → pipeline → gateway → update de estado funciona igual que WhatsApp, sin tocar DeliverAlertJob. */
    public function test_delivers_a_pending_push_recipient_and_marks_it_sent(): void
    {
        $profile = $this->createManagerProfile();
        PushSubscription::create([
            'user_id' => $profile->user_id,
            'endpoint' => 'https://fcm.test/deliver-alert-job',
            'endpoint_hash' => hash('sha256', 'https://fcm.test/deliver-alert-job'),
            'p256dh' => 'key',
            'auth_key' => 'auth',
        ]);

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Push,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $fakeGateway = new FakeGateway(Channel::Push);
        $fakeGateway->willReturn(DeliveryResult::sent('push-999'));
        app()->instance(FakeGateway::class, $fakeGateway);

        $gateways = new GatewayRegistry(app(), ['push' => ['gateway' => FakeGateway::class]]);

        $job = new DeliverAlertJob($recipient->id);
        $job->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Sent, $recipient->status);
        $this->assertSame('push-999', $recipient->provider_message_id);
        $this->assertCount(1, $fakeGateway->sentMessages());
    }

    public function test_discards_silently_when_the_recipient_no_longer_exists(): void
    {
        Queue::fake();
        Log::spy();

        $job = new DeliverAlertJob(999999);
        $job->handle($this->builders(), new GatewayRegistry(app(), []), new DeliveryPipeline([]), new ChannelFallbackService());

        Log::shouldHaveReceived('info')->once()->with('DeliverAlertJob: destinatario inexistente, se descarta', ['recipient_id' => 999999]);
        Queue::assertNothingPushed();
    }

    public function test_failed_hook_tolerates_a_recipient_that_no_longer_exists(): void
    {
        $job = new DeliverAlertJob(999999);
        $job->failed(new \RuntimeException('timeout'));

        $this->assertSame(0, AlertRecipient::count());
    }

    public function test_suppresses_without_sending_or_fallback_when_the_alert_was_cancelled(): void
    {
        Queue::fake();
        $profile = $this->createManagerProfile();

        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'cancelled',
        ]);
        $recipient = AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => Channel::Whatsapp,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $fakeGateway = new FakeGateway();
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        $job = new DeliverAlertJob($recipient->id);
        $job->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Suppressed, $recipient->status);
        $this->assertSame('no_longer_applicable', $recipient->failure_reason);
        $this->assertSame(\App\Notifications\Enums\FailureCode::NoLongerApplicable, $recipient->failure_code);
        $this->assertSame(0, $recipient->attempts);
        $this->assertCount(0, $fakeGateway->sentMessages());
        Queue::assertNothingPushed();
    }

    /** Builder returning a fixed content, to exercise the traceability of what was rendered. */
    private function contentBuilders(MessageContent $content): MessageBuilderRegistry
    {
        $builder = new class($content) implements AlertMessageBuilder {
            public function __construct(private readonly MessageContent $content) {}

            public function type(): AlertType
            {
                return AlertType::ProgramCreated;
            }

            public function build(Alert $alert, Recipient $recipient): MessageContent
            {
                return $this->content;
            }
        };

        return new MessageBuilderRegistry([$builder]);
    }

    private function pendingRecipient(UserProfile $profile, Channel $channel = Channel::Whatsapp): AlertRecipient
    {
        $alert = Alert::create([
            'type' => AlertType::ProgramCreated,
            'payload' => [],
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        return AlertRecipient::create([
            'alert_id' => $alert->id,
            'user_profile_id' => $profile->id,
            'channel' => $channel,
            'status' => DeliveryStatus::Pending,
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }

    public function test_records_destination_hint_rendered_body_and_template_version_on_send(): void
    {
        $recipient = $this->pendingRecipient($this->createManagerProfile());

        $fakeGateway = new FakeGateway();
        $fakeGateway->willReturn(DeliveryResult::sent('SM1'));
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        $content = new TemplateContent(AlertType::ProgramCreated, [1 => 'Lucas', 2 => 'Sincronización IATF']);

        (new DeliverAlertJob($recipient->id))->handle($this->contentBuilders($content), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame('+54 ••• •••• 4455', $recipient->destination_hint);
        $this->assertSame('Hola Lucas, se creó el programa "Sincronización IATF".', $recipient->rendered_body);
        $this->assertSame(1, $recipient->template_version);
        $this->assertNull($recipient->failure_code);
        // The raw number must never be persisted in the hint.
        $this->assertStringNotContainsString('5491122334455', (string) $recipient->destination_hint);
    }

    public function test_traceability_is_kept_when_the_gateway_fails_and_the_provider_code_is_classified(): void
    {
        Queue::fake();
        $recipient = $this->pendingRecipient($this->createManagerProfile());

        $fakeGateway = new FakeGateway();
        $fakeGateway->willReturn(DeliveryResult::failed('[131026] not on whatsapp', FailureCode::InvalidNumber));
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        $content = new TemplateContent(AlertType::ProgramCreated, [1 => 'Lucas', 2 => 'Sincro']);

        (new DeliverAlertJob($recipient->id))->handle($this->contentBuilders($content), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);
        $this->assertSame(FailureCode::InvalidNumber, $recipient->failure_code);
        $this->assertSame('[131026] not on whatsapp', $recipient->failure_reason);
        $this->assertNotNull($recipient->destination_hint);
        $this->assertNotNull($recipient->rendered_body);
    }

    public function test_a_failed_result_without_a_code_is_unknown(): void
    {
        Queue::fake();
        $recipient = $this->pendingRecipient($this->createManagerProfile());

        $fakeGateway = new FakeGateway();
        $fakeGateway->willReturn(DeliveryResult::failed('boom'));
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        (new DeliverAlertJob($recipient->id))->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $this->assertSame(FailureCode::Unknown, $recipient->refresh()->failure_code);
    }

    public function test_an_implausible_phone_fails_as_invalid_number_without_calling_the_provider_and_falls_back(): void
    {
        Queue::fake();
        $profile = $this->createManagerProfile();
        $profile->contacts()->update(['value' => '549112233']); // AR number too short
        $recipient = $this->pendingRecipient($profile);

        $fakeGateway = new FakeGateway();
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        (new DeliverAlertJob($recipient->id))->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Failed, $recipient->status);
        $this->assertSame(FailureCode::InvalidNumber, $recipient->failure_code);
        $this->assertSame('+54 ••• •••• 2233', $recipient->destination_hint);
        $this->assertCount(0, $fakeGateway->sentMessages());

        $fallback = AlertRecipient::where('alert_id', $recipient->alert_id)->where('channel', Channel::Email)->firstOrFail();
        Queue::assertPushed(DeliverAlertJob::class, fn ($job) => $job->recipientId === $fallback->id);
    }

    public function test_a_missing_contact_is_classified_by_channel(): void
    {
        Queue::fake();
        $profile = $this->createManagerProfile();
        $profile->contacts()->update(['use_for_alerts' => false]);
        $recipient = $this->pendingRecipient($profile);

        (new DeliverAlertJob($recipient->id))->handle($this->builders(), new GatewayRegistry(app(), []), new DeliveryPipeline([]), new ChannelFallbackService());

        $this->assertSame(FailureCode::NoWhatsappContact, $recipient->refresh()->failure_code);
    }

    public function test_a_missing_email_contact_is_classified_as_no_email_contact(): void
    {
        Queue::fake();
        $profile = $this->createManagerProfile(); // WhatsApp only, no email contact
        $recipient = $this->pendingRecipient($profile, Channel::Email);

        (new DeliverAlertJob($recipient->id))->handle($this->builders(), new GatewayRegistry(app(), []), new DeliveryPipeline([]), new ChannelFallbackService());

        $this->assertSame(FailureCode::NoEmailContact, $recipient->refresh()->failure_code);
    }

    public function test_an_email_recipient_gets_a_masked_email_hint_and_the_email_body(): void
    {
        $profile = $this->createManagerProfile();
        Contact::create([
            'contactable_type' => 'user_profile',
            'contactable_id' => $profile->id,
            'type' => ContactType::Email,
            'value' => 'maria@dominio.com',
            'is_primary' => false,
            'use_for_alerts' => true,
        ]);
        $recipient = $this->pendingRecipient($profile, Channel::Email);

        $fakeGateway = new FakeGateway(Channel::Email);
        $fakeGateway->willReturn(DeliveryResult::sent('mail-1'));
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['email' => ['gateway' => FakeGateway::class]]);

        (new DeliverAlertJob($recipient->id))->handle(
            $this->contentBuilders(new EmailContent('Asunto', 'Cuerpo del correo')),
            $gateways,
            new DeliveryPipeline([]),
            new ChannelFallbackService(),
        );

        $recipient->refresh();
        $this->assertSame('m•••@dominio.com', $recipient->destination_hint);
        $this->assertSame('Cuerpo del correo', $recipient->rendered_body);
        $this->assertNull($recipient->template_version);
    }

    public function test_push_recipients_store_no_address_and_no_body(): void
    {
        $profile = $this->createManagerProfile();
        PushSubscription::create([
            'user_id' => $profile->user_id,
            'endpoint' => 'https://fcm.test/trace',
            'endpoint_hash' => hash('sha256', 'https://fcm.test/trace'),
            'p256dh' => 'key',
            'auth_key' => 'auth',
        ]);
        $recipient = $this->pendingRecipient($profile, Channel::Push);

        $fakeGateway = new FakeGateway(Channel::Push);
        $fakeGateway->willReturn(DeliveryResult::sent('push-1'));
        app()->instance(FakeGateway::class, $fakeGateway);
        $gateways = new GatewayRegistry(app(), ['push' => ['gateway' => FakeGateway::class]]);

        (new DeliverAlertJob($recipient->id))->handle(
            $this->contentBuilders(new PushContent('t', 'b', 'alert-x')),
            $gateways,
            new DeliveryPipeline([]),
            new ChannelFallbackService(),
        );

        $recipient->refresh();
        $this->assertNull($recipient->destination_hint);
        $this->assertNull($recipient->rendered_body);
    }

    public function test_pipeline_suppression_records_the_matching_failure_code(): void
    {
        $profile = $this->createManagerProfile();
        \App\Notifications\Models\OptOut::create(['phone' => '5491122334455', 'channel' => Channel::Whatsapp]);
        $recipient = $this->pendingRecipient($profile);

        (new DeliverAlertJob($recipient->id))->handle($this->builders(), new GatewayRegistry(app(), []), new DeliveryPipeline([new OptOutPolicy()]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Suppressed, $recipient->status);
        $this->assertSame(FailureCode::OptedOut, $recipient->failure_code);
    }

    public function test_a_null_builder_content_records_no_longer_applicable_code(): void
    {
        $recipient = $this->pendingRecipient($this->createManagerProfile());

        (new DeliverAlertJob($recipient->id))->handle($this->nullBuilders(), new GatewayRegistry(app(), []), new DeliveryPipeline([]), new ChannelFallbackService());

        $this->assertSame(FailureCode::NoLongerApplicable, $recipient->refresh()->failure_code);
    }

    public function test_a_misconfigured_gateway_is_classified_and_reported_to_operations(): void
    {
        Queue::fake();
        Mail::fake();
        Cache::flush();
        config(['notifications.ops_email' => 'ops@example.com']);

        $recipient = $this->pendingRecipient($this->createManagerProfile());
        $gateways = new GatewayRegistry(app(), []); // whatsapp not declared

        (new DeliverAlertJob($recipient->id))->handle($this->builders(), $gateways, new DeliveryPipeline([]), new ChannelFallbackService());

        $recipient->refresh();
        $this->assertSame(FailureCode::ServiceMisconfigured, $recipient->failure_code);
        Mail::assertSentCount(1);
    }

    public function test_failed_hook_records_provider_unreachable(): void
    {
        Queue::fake();
        $recipient = $this->pendingRecipient($this->createManagerProfile());

        (new DeliverAlertJob($recipient->id))->failed(new \RuntimeException('Twilio timeout'));

        $this->assertSame(FailureCode::ProviderUnreachable, $recipient->refresh()->failure_code);
    }
}
