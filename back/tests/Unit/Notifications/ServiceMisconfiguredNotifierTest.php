<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Services\ServiceMisconfiguredNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\CreatesNotificationFixtures;
use Tests\TestCase;

class ServiceMisconfiguredNotifierTest extends TestCase
{
    use CreatesNotificationFixtures, RefreshDatabase;

    private function recipient(): AlertRecipient
    {
        return $this->createRecipient(
            $this->createManagerProfile(),
            $this->createAlert(),
            Channel::Whatsapp,
            DeliveryStatus::Failed,
        );
    }

    public function test_always_logs_critical_and_sends_no_mail_without_an_ops_address(): void
    {
        Log::spy();
        Mail::fake();
        config(['notifications.ops_email' => null]);

        (new ServiceMisconfiguredNotifier())->report($this->recipient(), new RuntimeException('Falta KAPSO_API_KEY.'));

        Log::shouldHaveReceived('critical')->once();
        Mail::assertNothingOutgoing();
    }

    public function test_mails_the_ops_address_at_most_once_per_hour(): void
    {
        Log::spy();
        Mail::fake();
        Cache::flush();
        config(['notifications.ops_email' => 'ops@example.com']);

        $recipient = $this->recipient();
        $notifier = new ServiceMisconfiguredNotifier();

        $notifier->report($recipient, new RuntimeException('uno'));
        $notifier->report($recipient, new RuntimeException('dos'));

        Log::shouldHaveReceived('critical')->twice();
        Mail::assertSentCount(1);
    }
}
