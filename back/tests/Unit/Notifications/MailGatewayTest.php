<?php

namespace Tests\Unit\Notifications;

use App\Mail\AlertMail;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Gateways\Mail\MailGateway;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailGatewayTest extends TestCase
{
    public function test_sends_email_content_via_laravel_mail(): void
    {
        Mail::fake();

        $message = new OutboundMessage(
            recipient: new Recipient(userId: 1, phone: null, name: 'Juan', channel: Channel::Email, email: 'juan@example.com'),
            content: new EmailContent(subject: 'Programa creado', body: 'Hola Juan'),
            channel: Channel::Email,
            idempotencyKey: 'key-1',
        );

        $result = (new MailGateway())->send($message);

        $this->assertSame(DeliveryStatus::Sent, $result->status);
        Mail::assertSent(AlertMail::class, function (AlertMail $mail) {
            return $mail->hasTo('juan@example.com') && $mail->alertSubject === 'Programa creado';
        });
    }

    public function test_fails_without_throwing_when_recipient_has_no_email(): void
    {
        Mail::fake();

        $message = new OutboundMessage(
            recipient: new Recipient(userId: 1, phone: null, name: 'Juan', channel: Channel::Email, email: null),
            content: new EmailContent(subject: 'Programa creado', body: 'Hola Juan'),
            channel: Channel::Email,
            idempotencyKey: 'key-1',
        );

        $result = (new MailGateway())->send($message);

        $this->assertSame(DeliveryStatus::Failed, $result->status);
        Mail::assertNothingSent();
    }

    public function test_rejects_non_email_content(): void
    {
        $message = new OutboundMessage(
            recipient: new Recipient(userId: 1, phone: null, name: 'Juan', channel: Channel::Email, email: 'juan@example.com'),
            content: new TextContent('hola'),
            channel: Channel::Email,
            idempotencyKey: 'key-1',
        );

        $this->expectException(\InvalidArgumentException::class);

        (new MailGateway())->send($message);
    }
}
