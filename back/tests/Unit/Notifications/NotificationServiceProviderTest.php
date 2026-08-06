<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Gateways\Twilio\TwilioWhatsappGateway;
use Tests\TestCase;

/**
 * Covers the credential validation NotificationServiceProvider performs when resolving
 * TwilioWhatsappGateway from the container. TwilioWhatsappGatewayTest constructs the gateway
 * directly and therefore never exercises this validation, so it had zero coverage.
 */
class NotificationServiceProviderTest extends TestCase
{
    public function test_resolving_the_twilio_gateway_without_credentials_throws_a_configuration_exception(): void
    {
        app()->forgetInstance(TwilioWhatsappGateway::class);
        config()->set('notifications.twilio.sid', '');
        config()->set('notifications.twilio.token', '');
        config()->set('notifications.twilio.messaging_service', 'MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

        $this->expectException(NotificationConfigurationException::class);
        $this->expectExceptionMessage('Faltan TWILIO_ACCOUNT_SID y/o TWILIO_AUTH_TOKEN.');

        app(TwilioWhatsappGateway::class);
    }

    public function test_resolving_the_twilio_gateway_without_a_messaging_service_throws_a_configuration_exception(): void
    {
        app()->forgetInstance(TwilioWhatsappGateway::class);
        config()->set('notifications.twilio.sid', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
        config()->set('notifications.twilio.token', 'tokenxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
        config()->set('notifications.twilio.messaging_service', '');

        $this->expectException(NotificationConfigurationException::class);
        $this->expectExceptionMessage('Falta TWILIO_TEMPLATE_MESSAGE_SERVICE.');

        app(TwilioWhatsappGateway::class);
    }
}
