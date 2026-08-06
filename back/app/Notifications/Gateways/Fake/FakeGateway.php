<?php

namespace App\Notifications\Gateways\Fake;

use App\Notifications\Contracts\NotificationChannelGateway;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Enums\Channel;

final class FakeGateway implements NotificationChannelGateway
{
    /** @var OutboundMessage[] */
    private array $sent = [];

    private ?DeliveryResult $nextResult = null;

    public function __construct(private readonly Channel $fakeChannel = Channel::Whatsapp) {}

    public function channel(): Channel
    {
        return $this->fakeChannel;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $this->sent[] = $message;

        return $this->nextResult ?? DeliveryResult::sent('fake-' . count($this->sent));
    }

    public function willReturn(DeliveryResult $result): void
    {
        $this->nextResult = $result;
    }

    /** @return OutboundMessage[] */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    public function assertSentCount(int $count): bool
    {
        return count($this->sent) === $count;
    }
}
