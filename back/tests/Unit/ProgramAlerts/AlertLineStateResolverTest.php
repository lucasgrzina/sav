<?php

namespace Tests\Unit\ProgramAlerts;

use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Services\ProgramAlerts\AlertLineStateResolver;
use App\Services\ProgramAlerts\Data\AlertLineState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertLineStateResolverTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2030-06-01 10:00:00', 'UTC');
    }

    private function alert(string $status = 'dispatched', string $scheduledAt = '2030-06-01 09:00:00'): Alert
    {
        return new Alert(['status' => $status, 'scheduled_at' => $scheduledAt]);
    }

    private function attempt(Channel $channel, DeliveryStatus $status, ?FailureCode $code = null): AlertRecipient
    {
        return new AlertRecipient(['channel' => $channel, 'status' => $status, 'failure_code' => $code]);
    }

    /** @param list<AlertRecipient> $attempts */
    private function resolve(Alert $alert, array $attempts): AlertLineState
    {
        return (new AlertLineStateResolver())->resolve($alert, new Collection($attempts), $this->now);
    }

    /** @return array<string, array{0: string, 1: list<array{0: Channel, 1: DeliveryStatus, 2?: ?FailureCode}>, 3: string, 4: string, 5: bool}> */
    public static function scenarios(): array
    {
        $w = Channel::Whatsapp;
        $e = Channel::Email;

        return [
            'whatsapp sent only' => ['dispatched', [[$w, DeliveryStatus::Sent]], 'sent', 'sent', false],
            'whatsapp delivered' => ['dispatched', [[$w, DeliveryStatus::Delivered]], 'delivered', 'sent', false],
            'whatsapp read' => ['dispatched', [[$w, DeliveryStatus::Read]], 'read', 'sent', false],
            'whatsapp failed, email sent is one sent line with fallback' => [
                'dispatched', [[$w, DeliveryStatus::Failed, FailureCode::InvalidNumber], [$e, DeliveryStatus::Sent]], 'sent', 'sent', true,
            ],
            'whatsapp sent wins over email read' => [
                'dispatched', [[$w, DeliveryStatus::Sent], [$e, DeliveryStatus::Read]], 'sent', 'sent', false,
            ],
            'whatsapp failed, email pending' => [
                'dispatched', [[$w, DeliveryStatus::Failed, FailureCode::ProviderUnreachable], [$e, DeliveryStatus::Pending]], 'in_process', 'scheduled', false,
            ],
            'pending and future' => ['pending', [[$w, DeliveryStatus::Pending]], 'scheduled', 'scheduled', false],
            'dispatched and attempt pending' => ['dispatched', [[$w, DeliveryStatus::Pending]], 'in_process', 'scheduled', false],
            'whatsapp failed alone' => ['dispatched', [[$w, DeliveryStatus::Failed, FailureCode::NoWhatsappContact]], 'failed', 'problem', false],
            'whatsapp failed and email failed' => [
                'dispatched', [[$w, DeliveryStatus::Failed, FailureCode::InvalidNumber], [$e, DeliveryStatus::Failed, FailureCode::NoEmailContact]], 'failed', 'problem', false,
            ],
            'suppressed opted out' => ['dispatched', [[$w, DeliveryStatus::Suppressed, FailureCode::OptedOut]], 'not_sent', 'problem', false],
            'alert cancelled' => ['cancelled', [[$w, DeliveryStatus::Pending]], 'cancelled', 'cancelled', false],
            'person no longer responsible' => ['pending', [[$w, DeliveryStatus::Suppressed, FailureCode::NoLongerApplicable]], 'cancelled', 'cancelled', false],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_state_table(string $alertStatus, array $attempts, string $state, string $bucket, bool $fallback): void
    {
        $scheduledAt = $alertStatus === 'pending' ? '2030-06-01 12:00:00' : '2030-06-01 09:00:00';
        $built = array_map(fn (array $a) => $this->attempt($a[0], $a[1], $a[2] ?? null), $attempts);

        $result = $this->resolve($this->alert($alertStatus, $scheduledAt), $built);

        $this->assertSame($state, $result->state);
        $this->assertSame($bucket, $result->bucket);
        $this->assertSame($fallback, $result->fallbackUsed);
    }

    public function test_pending_alert_already_due_is_in_process_not_scheduled(): void
    {
        $result = $this->resolve($this->alert('pending', '2030-06-01 09:00:00'), [$this->attempt(Channel::Whatsapp, DeliveryStatus::Pending)]);

        $this->assertSame('in_process', $result->state);
        $this->assertSame('scheduled', $result->bucket);
    }

    public function test_failed_without_code_is_unknown(): void
    {
        $result = $this->resolve($this->alert(), [$this->attempt(Channel::Whatsapp, DeliveryStatus::Failed)]);

        $this->assertSame('failed', $result->state);
        $this->assertSame(FailureCode::Unknown, $result->failureCode);
    }

    public function test_sent_is_never_promoted_to_delivered(): void
    {
        $attempt = $this->attempt(Channel::Whatsapp, DeliveryStatus::Sent);
        $attempt->delivered_at = null;

        $this->assertSame('sent', $this->resolve($this->alert(), [$attempt])->state);
    }

    public function test_primary_is_the_whatsapp_attempt_when_everything_failed(): void
    {
        $whatsapp = $this->attempt(Channel::Whatsapp, DeliveryStatus::Failed, FailureCode::InvalidNumber);
        $email = $this->attempt(Channel::Email, DeliveryStatus::Failed, FailureCode::NoEmailContact);

        $result = $this->resolve($this->alert(), [$email, $whatsapp]);

        $this->assertSame($whatsapp, $result->primary);
        $this->assertSame(FailureCode::InvalidNumber, $result->failureCode);
    }
}
