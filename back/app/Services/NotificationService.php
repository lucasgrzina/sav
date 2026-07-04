<?php

namespace App\Services;

use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Events\News;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use Illuminate\Support\Facades\Auth;

class NotificationService
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository,
    ) {}

    /**
     * Crea una notificación y la despacha en tiempo real a cada destinatario.
     *
     * Uso desde otros Services/Jobs:
     *   $this->notificationService->store([
     *       'payload'  => ['title' => '...', 'description' => '...', 'url' => '/...', 'type' => 'info'],
     *       'user_ids' => [1, 2, 3],
     *   ]);
     *
     * @param  array  $data  { payload: array, user_ids: int[] }
     */
    public function store(array $data): Notification
    {
        $notification = $this->notificationRepository->create([
            'payload'    => $data['payload'],
            'user_ids'   => $data['user_ids'],
            'created_by' => Auth::id(),
        ]);

        // Despachar evento WebSocket para cada destinatario individualmente
        foreach ($data['user_ids'] as $userId) {
            event(new News(
                guid:            $notification->guid,
                payload:         $notification->payload,
                recipientUserId: (int) $userId,
                isRead:          false,
            ));
        }

        return $notification;
    }

    /**
     * Marca como leída la notificación $guid para el usuario $userId.
     * Solo actualiza el pivot del usuario — otros destinatarios no se afectan.
     */
    public function markAsRead(string $guid, int $userId): void
    {
        $notification = $this->notificationRepository->findByGuid($guid);

        if (! $notification) {
            return;
        }

        NotificationRecipient::where('notification_id', $notification->id)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Marca todas las notificaciones no leídas del usuario como leídas.
     */
    public function markAllAsRead(int $userId): void
    {
        NotificationRecipient::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Lista paginada de notificaciones del usuario.
     *
     * @return array{ notifications: \Illuminate\Contracts\Pagination\LengthAwarePaginator, unread_count: int }
     */
    public function list(int $userId, array $params): array
    {
        return $this->notificationRepository->list($userId, $params);
    }

    /**
     * Últimas notificaciones para el dropdown de la campana.
     *
     * @return array{ notifications: \Illuminate\Support\Collection, unread_count: int }
     */
    public function latest(int $userId): array
    {
        return $this->notificationRepository->latest($userId, 10);
    }

    public function findByGuid(string $guid): ?Notification
    {
        return $this->notificationRepository->findByGuid($guid);
    }
}
