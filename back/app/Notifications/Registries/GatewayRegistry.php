<?php

namespace App\Notifications\Registries;

use App\Notifications\Contracts\NotificationChannelGateway;
use App\Notifications\Enums\Channel;
use App\Notifications\Exceptions\NotificationConfigurationException;
use Illuminate\Contracts\Container\Container;

final class GatewayRegistry
{
    /** @var array<string, array{gateway: ?class-string<NotificationChannelGateway>, provider: ?string, available: string[]}> */
    private array $map;

    /**
     * @param array<string, array{
     *     gateway: ?class-string<NotificationChannelGateway>,
     *     provider?: ?string,
     *     available?: string[],
     * }> $config
     */
    public function __construct(
        private readonly Container $container,
        array $config,
    ) {
        $this->map = collect($config)
            ->mapWithKeys(fn ($channelConfig, $channel) => [$channel => [
                'gateway' => $channelConfig['gateway'],
                'provider' => $channelConfig['provider'] ?? null,
                'available' => $channelConfig['available'] ?? [],
            ]])
            ->all();
    }

    public function for(Channel $channel): NotificationChannelGateway
    {
        // Un canal ausente del config es tan definitivo como uno con gateway null: no hay
        // nada que reintentar hasta que se lo configure, así que debe tomar el mismo camino
        // de fallback inmediato en DeliverAlertJob en vez de agotar los 5 reintentos.
        $channelConfig = $this->map[$channel->value]
            ?? throw new NotificationConfigurationException("Sin gateway para canal {$channel->value}");

        $class = $channelConfig['gateway'] ?? throw new NotificationConfigurationException(
            $this->unresolvedGatewayMessage($channel, $channelConfig),
        );

        return $this->container->make($class);
    }

    /** @param array{gateway: null, provider: ?string, available: string[]} $channelConfig */
    private function unresolvedGatewayMessage(Channel $channel, array $channelConfig): string
    {
        if (empty($channelConfig['provider'])) {
            return "Sin gateway configurado para canal {$channel->value}.";
        }

        return sprintf(
            "%s_PROVIDER inválido: '%s'. Disponibles: %s",
            strtoupper($channel->value),
            $channelConfig['provider'],
            implode(', ', $channelConfig['available']),
        );
    }
}
