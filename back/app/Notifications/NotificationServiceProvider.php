<?php

namespace App\Notifications;

use App\Notifications\Builders\ProgramCancelledMessageBuilder;
use App\Notifications\Builders\ProgramCreatedMessageBuilder;
use App\Notifications\Builders\ProgramPdfShareMessageBuilder;
use App\Notifications\Builders\ProgramTaskDueMessageBuilder;
use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Gateways\Kapso\KapsoWhatsappGateway;
use App\Notifications\Gateways\Twilio\TwilioWhatsappGateway;
use App\Notifications\Pipeline\DeliveryPipeline;
use App\Notifications\Policies\OptOutPolicy;
use App\Notifications\Registries\GatewayRegistry;
use App\Notifications\Registries\MessageBuilderRegistry;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Twilio\Rest\Client;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/notifications.php', 'notifications');

        $this->app->singleton(TwilioWhatsappGateway::class, function ($app) {
            $config = $app['config']->get('notifications.twilio');
            $sid = trim((string) ($config['sid'] ?? ''));
            $token = trim((string) ($config['token'] ?? ''));
            $messagingService = trim((string) ($config['messaging_service'] ?? ''));

            if ($sid === '' || $token === '') {
                throw new NotificationConfigurationException('Faltan TWILIO_ACCOUNT_SID y/o TWILIO_AUTH_TOKEN.');
            }

            if ($messagingService === '') {
                throw new NotificationConfigurationException('Falta TWILIO_TEMPLATE_MESSAGE_SERVICE.');
            }

            return new TwilioWhatsappGateway(
                new Client($sid, $token),
                $messagingService,
                $config['templates'] ?? [],
                trim((string) ($config['status_callback_url'] ?? '')) ?: route('webhooks.twilio'),
            );
        });

        $this->app->singleton(KapsoWhatsappGateway::class, function ($app) {
            $config = $app['config']->get('notifications.kapso');
            $phoneNumberId = trim((string) ($config['phone_number_id'] ?? ''));

            if ($phoneNumberId === '') {
                throw new NotificationConfigurationException('Falta KAPSO_PHONE_NUMBER_ID.');
            }

            return new KapsoWhatsappGateway(
                $app->make(HttpFactory::class),
                sprintf(
                    '%s/meta/whatsapp/%s/%s/messages',
                    rtrim((string) $config['base_url'], '/'),
                    trim((string) $config['api_version'], '/'),
                    $phoneNumberId,
                ),
                trim((string) ($config['api_key'] ?? '')),
                $config['templates'] ?? [],
                (int) ($config['timeout'] ?? 10),
            );
        });

        $this->app->singleton(GatewayRegistry::class, function ($app) {
            return new GatewayRegistry($app, $app['config']->get('notifications.channels'));
        });

        $this->app->tag([
            ProgramCreatedMessageBuilder::class,
            ProgramCancelledMessageBuilder::class,
            ProgramTaskDueMessageBuilder::class,
            ProgramPdfShareMessageBuilder::class,
        ], 'alert.builders');

        $this->app->singleton(MessageBuilderRegistry::class, function ($app) {
            return new MessageBuilderRegistry(iterator_to_array($app->tagged('alert.builders')));
        });

        $this->app->tag([
            OptOutPolicy::class,
        ], 'alert.policies');

        $this->app->singleton(DeliveryPipeline::class, function ($app) {
            return new DeliveryPipeline(iterator_to_array($app->tagged('alert.policies')));
        });
    }
}
