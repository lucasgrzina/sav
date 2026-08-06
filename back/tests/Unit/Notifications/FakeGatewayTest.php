<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\SuppressionReason;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Gateways\Fake\FakeGateway;
use Tests\TestCase;

class FakeGatewayTest extends TestCase
{
    private function message(): OutboundMessage
    {
        return new OutboundMessage(
            recipient: new Recipient(userId: 1, phone: '5491122334455', name: 'Juan', channel: Channel::Whatsapp),
            content: new TextContent('hola'),
            channel: Channel::Whatsapp,
            idempotencyKey: 'key-1',
        );
    }

    public function test_records_sent_messages_and_defaults_to_sent_result(): void
    {
        $gateway = new FakeGateway();

        $result = $gateway->send($this->message());

        $this->assertSame(DeliveryStatus::Sent, $result->status);
        $this->assertTrue($gateway->assertSentCount(1));
        $this->assertCount(1, $gateway->sentMessages());
    }

    public function test_can_be_configured_to_return_a_suppressed_or_failed_result(): void
    {
        $gateway = new FakeGateway();
        $gateway->willReturn(DeliveryResult::suppressed(SuppressionReason::OptedOut));

        $result = $gateway->send($this->message());

        $this->assertSame(DeliveryStatus::Suppressed, $result->status);
    }
}
