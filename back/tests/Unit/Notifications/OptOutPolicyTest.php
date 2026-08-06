<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\SuppressionReason;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\OptOut;
use App\Notifications\Policies\OptOutPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptOutPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function message(string $phone, Channel $channel): OutboundMessage
    {
        return new OutboundMessage(
            recipient: new Recipient(userId: 1, phone: $phone, name: 'Juan', channel: $channel),
            content: new TextContent('hola'),
            channel: $channel,
            idempotencyKey: 'key-1',
        );
    }

    public function test_allows_delivery_when_recipient_has_not_opted_out(): void
    {
        $policy = new OptOutPolicy();

        $this->assertNull($policy->check($this->message('5491122334455', Channel::Whatsapp)));
    }

    public function test_suppresses_delivery_when_recipient_opted_out_on_the_same_channel(): void
    {
        OptOut::create(['phone' => '5491122334455', 'channel' => Channel::Whatsapp]);

        $policy = new OptOutPolicy();

        $this->assertSame(
            SuppressionReason::OptedOut,
            $policy->check($this->message('5491122334455', Channel::Whatsapp)),
        );
    }

    public function test_opt_out_on_one_channel_does_not_suppress_another_channel(): void
    {
        OptOut::create(['phone' => '5491122334455', 'channel' => Channel::Whatsapp]);

        $policy = new OptOutPolicy();

        $this->assertNull($policy->check($this->message('5491122334455', Channel::Sms)));
    }
}
