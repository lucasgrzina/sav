<?php

namespace Tests\Unit\Notifications;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\SuppressionReason;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
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
use Illuminate\Support\Facades\Log;
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
}
