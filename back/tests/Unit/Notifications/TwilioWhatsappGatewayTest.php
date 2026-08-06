<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Exceptions\TemplateNotConfiguredException;
use App\Notifications\Gateways\Twilio\TwilioWhatsappGateway;
use Mockery;
use Tests\TestCase;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Rest\Client;

class TwilioWhatsappGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Builds a real MessageInstance (no live constructor deps) exposing `sid` via its actual magic __get. */
    private function messageInstanceWithSid(string $sid): MessageInstance
    {
        $instance = (new \ReflectionClass(MessageInstance::class))->newInstanceWithoutConstructor();

        $property = new \ReflectionProperty(\Twilio\InstanceResource::class, 'properties');
        $property->setAccessible(true);
        $property->setValue($instance, ['sid' => $sid]);

        return $instance;
    }

    private function recipient(): Recipient
    {
        return new Recipient(
            userId: 1,
            phone: '5491122334455',
            name: 'Juan',
            channel: Channel::Whatsapp,
        );
    }

    public function test_sends_template_content_via_content_api(): void
    {
        $messageInstance = $this->messageInstanceWithSid('SM123');

        $messageList = Mockery::mock(MessageList::class);
        $messageList->shouldReceive('create')
            ->once()
            ->with('whatsapp:+5491122334455', [
                'from' => 'whatsapp:from',
                'contentSid' => 'HXfake',
                'contentVariables' => json_encode(['1' => 'Juan']),
            ])
            ->andReturn($messageInstance);

        $client = Mockery::mock(Client::class)->shouldAllowMockingProtectedMethods();
        $client->shouldReceive('getMessages')->andReturn($messageList);

        $gateway = new TwilioWhatsappGateway($client, 'whatsapp:from', ['program.created' => 'HXfake']);

        $message = new OutboundMessage(
            recipient: $this->recipient(),
            content: new TemplateContent(type: AlertType::ProgramCreated, variables: ['1' => 'Juan']),
            channel: Channel::Whatsapp,
            idempotencyKey: 'key-1',
        );

        $result = $gateway->send($message);

        $this->assertSame(DeliveryStatus::Sent, $result->status);
        $this->assertSame('SM123', $result->providerMessageId);
    }

    public function test_sends_text_content_as_free_form_body(): void
    {
        $messageInstance = $this->messageInstanceWithSid('SM456');

        $messageList = Mockery::mock(MessageList::class);
        $messageList->shouldReceive('create')
            ->once()
            ->with('whatsapp:+5491122334455', [
                'from' => 'whatsapp:from',
                'body' => 'hola',
            ])
            ->andReturn($messageInstance);

        $client = Mockery::mock(Client::class)->shouldAllowMockingProtectedMethods();
        $client->shouldReceive('getMessages')->andReturn($messageList);

        $gateway = new TwilioWhatsappGateway($client, 'whatsapp:from', ['program.created' => 'HXfake']);

        $result = $gateway->send(new OutboundMessage(
            recipient: $this->recipient(),
            content: new TextContent('hola'),
            channel: Channel::Whatsapp,
            idempotencyKey: 'key-2',
        ));

        $this->assertSame('SM456', $result->providerMessageId);
    }

    public function test_4xx_rest_exception_is_translated_to_failed_result_without_rethrowing(): void
    {
        $messageList = Mockery::mock(MessageList::class);
        $messageList->shouldReceive('create')
            ->once()
            ->andThrow(new RestException('Invalid phone number', 21211, 400));

        $client = Mockery::mock(Client::class)->shouldAllowMockingProtectedMethods();
        $client->shouldReceive('getMessages')->andReturn($messageList);

        $gateway = new TwilioWhatsappGateway($client, 'whatsapp:from', ['program.created' => 'HXfake']);

        $result = $gateway->send(new OutboundMessage(
            recipient: $this->recipient(),
            content: new TextContent('hola'),
            channel: Channel::Whatsapp,
            idempotencyKey: 'key-3',
        ));

        $this->assertSame(DeliveryStatus::Failed, $result->status);
        $this->assertSame('Invalid phone number', $result->failureReason);
    }

    public function test_an_unconfigured_template_throws_instead_of_reporting_a_delivery_failure(): void
    {
        $messageList = Mockery::mock(MessageList::class);
        $messageList->shouldNotReceive('create');

        $client = Mockery::mock(Client::class)->shouldAllowMockingProtectedMethods();
        $client->shouldReceive('getMessages')->andReturn($messageList);

        // No contentSid mapped for program.cancelled.
        $gateway = new TwilioWhatsappGateway($client, 'whatsapp:from', ['program.created' => 'HXfake']);

        $this->expectException(TemplateNotConfiguredException::class);

        $gateway->send(new OutboundMessage(
            recipient: $this->recipient(),
            content: new TemplateContent(type: AlertType::ProgramCancelled, variables: ['1' => 'Juan']),
            channel: Channel::Whatsapp,
            idempotencyKey: 'key-5',
        ));
    }

    /** DEC-14: statusCallback travels on every send when configured, so delivery callbacks
     *  do not depend on someone configuring it by hand in the Twilio console. */
    public function test_includes_the_status_callback_url_when_configured(): void
    {
        $messageInstance = $this->messageInstanceWithSid('SM789');

        $messageList = Mockery::mock(MessageList::class);
        $messageList->shouldReceive('create')
            ->once()
            ->with('whatsapp:+5491122334455', [
                'from' => 'whatsapp:from',
                'statusCallback' => 'https://sav.test/api/v1/webhooks/twilio',
                'body' => 'hola',
            ])
            ->andReturn($messageInstance);

        $client = Mockery::mock(Client::class)->shouldAllowMockingProtectedMethods();
        $client->shouldReceive('getMessages')->andReturn($messageList);

        $gateway = new TwilioWhatsappGateway(
            $client,
            'whatsapp:from',
            ['program.created' => 'HXfake'],
            'https://sav.test/api/v1/webhooks/twilio',
        );

        $result = $gateway->send(new OutboundMessage(
            recipient: $this->recipient(),
            content: new TextContent('hola'),
            channel: Channel::Whatsapp,
            idempotencyKey: 'key-6',
        ));

        $this->assertSame('SM789', $result->providerMessageId);
    }

    public function test_5xx_rest_exception_is_rethrown_for_queue_backoff(): void
    {
        $messageList = Mockery::mock(MessageList::class);
        $messageList->shouldReceive('create')
            ->once()
            ->andThrow(new RestException('Server error', 20500, 500));

        $client = Mockery::mock(Client::class)->shouldAllowMockingProtectedMethods();
        $client->shouldReceive('getMessages')->andReturn($messageList);

        $gateway = new TwilioWhatsappGateway($client, 'whatsapp:from', ['program.created' => 'HXfake']);

        $this->expectException(RestException::class);

        $gateway->send(new OutboundMessage(
            recipient: $this->recipient(),
            content: new TextContent('hola'),
            channel: Channel::Whatsapp,
            idempotencyKey: 'key-4',
        ));
    }
}
