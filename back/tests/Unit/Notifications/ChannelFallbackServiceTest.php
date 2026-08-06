<?php

namespace Tests\Unit\Notifications;

use App\Models\UserProfile;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Services\ChannelFallbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesNotificationFixtures;
use Tests\TestCase;

class ChannelFallbackServiceTest extends TestCase
{
    use CreatesNotificationFixtures, RefreshDatabase;

    private function failedWhatsappRecipient(UserProfile $profile, Alert $alert): AlertRecipient
    {
        $recipient = $this->createRecipient($profile, $alert, Channel::Whatsapp, DeliveryStatus::Failed);
        $recipient->update(['failure_reason' => 'numero invalido']);

        return $recipient;
    }

    public function test_creates_a_pending_email_recipient_and_dispatches_its_job(): void
    {
        Queue::fake();

        $profile = $this->createManagerProfile();
        $alert = $this->createAlert();
        $recipient = $this->failedWhatsappRecipient($profile, $alert);

        (new ChannelFallbackService())->attempt($recipient);

        $fallback = AlertRecipient::where('alert_id', $alert->id)
            ->where('channel', Channel::Email)
            ->firstOrFail();

        $this->assertSame(DeliveryStatus::Pending, $fallback->status);
        $this->assertSame($profile->id, $fallback->user_profile_id);
        $this->assertNotSame($recipient->idempotency_key, $fallback->idempotency_key);

        Queue::assertPushed(DeliverAlertJob::class, fn ($job) => $job->recipientId === $fallback->id);
    }

    public function test_skips_a_channel_already_attempted_for_the_same_alert_and_profile(): void
    {
        Queue::fake();

        $profile = $this->createManagerProfile();
        $alert = $this->createAlert();
        $recipient = $this->failedWhatsappRecipient($profile, $alert);

        $this->createRecipient($profile, $alert, Channel::Email, DeliveryStatus::Sent);

        (new ChannelFallbackService())->attempt($recipient);

        $this->assertSame(2, AlertRecipient::where('alert_id', $alert->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_does_nothing_when_the_channel_has_no_fallback_configured(): void
    {
        Queue::fake();
        config(['notifications.fallback' => []]);

        $profile = $this->createManagerProfile();
        $alert = $this->createAlert();
        $recipient = $this->failedWhatsappRecipient($profile, $alert);

        (new ChannelFallbackService())->attempt($recipient);

        $this->assertSame(1, AlertRecipient::where('alert_id', $alert->id)->count());
        Queue::assertNothingPushed();
    }

    /** A second failure on an already-escalated alert must not create a duplicate email attempt. */
    public function test_is_idempotent_across_repeated_attempts(): void
    {
        Queue::fake();

        $profile = $this->createManagerProfile();
        $alert = $this->createAlert();
        $recipient = $this->failedWhatsappRecipient($profile, $alert);

        $service = new ChannelFallbackService();
        $service->attempt($recipient);
        $service->attempt($recipient);

        $this->assertSame(1, AlertRecipient::where('alert_id', $alert->id)->where('channel', Channel::Email)->count());
        Queue::assertPushed(DeliverAlertJob::class, 1);
    }

    /** Only the failing profile is escalated: other recipients of the same alert are untouched. */
    public function test_escalates_only_the_failing_profile(): void
    {
        Queue::fake();

        $failing = $this->createManagerProfile();
        $other = $this->createManagerProfile();
        $alert = $this->createAlert();

        $recipient = $this->failedWhatsappRecipient($failing, $alert);
        $this->createRecipient($other, $alert, Channel::Whatsapp, DeliveryStatus::Sent);

        (new ChannelFallbackService())->attempt($recipient);

        $emailRecipients = AlertRecipient::where('alert_id', $alert->id)->where('channel', Channel::Email);

        $this->assertSame(1, $emailRecipients->count());
        $this->assertSame($failing->id, $emailRecipients->value('user_profile_id'));
    }
}
