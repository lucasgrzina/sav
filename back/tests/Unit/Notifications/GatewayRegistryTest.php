<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Enums\Channel;
use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Gateways\Fake\FakeGateway;
use App\Notifications\Registries\GatewayRegistry;
use Tests\TestCase;

class GatewayRegistryTest extends TestCase
{
    public function test_a_null_gateway_throws_a_configuration_exception_naming_the_invalid_provider(): void
    {
        $gateways = new GatewayRegistry(app(), [
            'whatsapp' => [
                'provider' => 'bogus',
                'gateway' => null,
                'available' => ['twilio', 'kapso', 'fake'],
            ],
        ]);

        $this->expectException(NotificationConfigurationException::class);
        $this->expectExceptionMessage("WHATSAPP_PROVIDER inválido: 'bogus'. Disponibles: twilio, kapso, fake");

        $gateways->for(Channel::Whatsapp);
    }

    public function test_a_channel_absent_from_config_throws_a_configuration_exception(): void
    {
        $gateways = new GatewayRegistry(app(), []);

        $this->expectException(NotificationConfigurationException::class);
        $this->expectExceptionMessage('Sin gateway para canal whatsapp');

        $gateways->for(Channel::Whatsapp);
    }

    public function test_resolves_a_configured_gateway_without_provider_or_available_keys(): void
    {
        $gateways = new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]);

        $this->assertInstanceOf(FakeGateway::class, $gateways->for(Channel::Whatsapp));
    }

    public function test_a_null_gateway_without_a_provider_key_produces_the_generic_message(): void
    {
        $gateways = new GatewayRegistry(app(), [
            'whatsapp' => ['gateway' => null],
        ]);

        $this->expectException(NotificationConfigurationException::class);
        $this->expectExceptionMessage('Sin gateway configurado para canal whatsapp.');

        $gateways->for(Channel::Whatsapp);
    }

    public function test_a_null_gateway_with_an_empty_provider_produces_the_generic_message(): void
    {
        $gateways = new GatewayRegistry(app(), [
            'whatsapp' => ['gateway' => null, 'provider' => ''],
        ]);

        $this->expectException(NotificationConfigurationException::class);
        $this->expectExceptionMessage('Sin gateway configurado para canal whatsapp.');

        $gateways->for(Channel::Whatsapp);
    }
}
