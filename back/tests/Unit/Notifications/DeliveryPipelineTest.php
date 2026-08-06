<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Contracts\DeliveryPolicy;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\SuppressionReason;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\Channel;
use App\Notifications\Pipeline\DeliveryPipeline;
use Tests\TestCase;

class DeliveryPipelineTest extends TestCase
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

    private function policyThatReturns(?SuppressionReason $reason): DeliveryPolicy
    {
        return new class($reason) implements DeliveryPolicy {
            public function __construct(private readonly ?SuppressionReason $reason) {}

            public function check(OutboundMessage $message): ?SuppressionReason
            {
                return $this->reason;
            }
        };
    }

    public function test_returns_null_when_no_policy_suppresses(): void
    {
        $pipeline = new DeliveryPipeline([
            $this->policyThatReturns(null),
            $this->policyThatReturns(null),
        ]);

        $this->assertNull($pipeline->run($this->message()));
    }

    public function test_returns_the_first_suppression_reason_and_stops_the_chain(): void
    {
        $secondPolicyThatMustNotRun = new class implements DeliveryPolicy {
            public function check(OutboundMessage $message): ?SuppressionReason
            {
                throw new \RuntimeException('Pipeline did not stop at the first suppression reason.');
            }
        };

        $pipeline = new DeliveryPipeline([
            $this->policyThatReturns(SuppressionReason::OptedOut),
            $secondPolicyThatMustNotRun,
        ]);

        $reason = $pipeline->run($this->message());

        $this->assertSame(SuppressionReason::OptedOut, $reason);
    }
}
