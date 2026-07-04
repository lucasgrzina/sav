<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class News implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $guid,
        public readonly array  $payload,
        public readonly int    $recipientUserId,
        public readonly bool   $isRead = false,
    ) {}

    /**
     * Canal privado por usuario: private-app.user.{recipientUserId}
     * Requiere autenticación en /broadcasting/auth
     *
     * @return array<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("app.user.{$this->recipientUserId}"),
        ];
    }

    /**
     * Nombre del evento en el frontend: '.app.event'
     * El punto inicial es obligatorio cuando viene de broadcastAs().
     */
    public function broadcastAs(): string
    {
        return 'app.event';
    }

    /**
     * Payload enviado al frontend vía WebSocket.
     */
    public function broadcastWith(): array
    {
        return [
            'event'   => 'news',
            'payload' => [
                'guid'    => $this->guid,
                'data'    => $this->payload,
                'user_id' => $this->recipientUserId,
                'is_read' => $this->isRead,
            ],
        ];
    }
}
