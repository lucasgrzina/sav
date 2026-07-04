<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Models\Notification;
use App\Models\NotificationRecipient;

class NotificationRepositoryEloquent extends BaseRepositoryEloquent implements NotificationRepositoryInterface
{
    protected function model(): string
    {
        return Notification::class;
    }

    public function findByGuid(string $guid): ?Notification
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    /**
     * Lista paginada. Filtra por destinatario y carga el pivot read_at
     * solo para el usuario solicitante.
     */
    public function list(int $userId, array $params): array
    {
        $perPage = (int) ($params['per_page'] ?? 15);

        $paginator = $this->newQuery()
            ->whereHas('recipients', fn ($q) => $q->where('user_id', $userId))
            ->with(['recipients' => fn ($q) => $q->where('user_id', $userId)])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return [
            'notifications' => $paginator,
            'unread_count'  => $this->getUnreadCount($userId),
        ];
    }

    /**
     * Últimas $limit notificaciones para el dropdown de la campana.
     */
    public function latest(int $userId, int $limit = 10): array
    {
        $notifications = $this->newQuery()
            ->whereHas('recipients', fn ($q) => $q->where('user_id', $userId))
            ->with(['recipients' => fn ($q) => $q->where('user_id', $userId)])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'notifications' => $notifications,
            'unread_count'  => $this->getUnreadCount($userId),
        ];
    }

    public function getUnreadCount(int $userId): int
    {
        return NotificationRecipient::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Crea la notificación y adjunta los destinatarios en la tabla pivot.
     * No dispara el evento de broadcasting — eso lo hace el Service.
     */
    public function create(array $data): Notification
    {
        /** @var Notification $notification */
        $notification = $this->model->newQuery()->create([
            'payload'    => $data['payload'],
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['created_by'] ?? null,
        ]);

        // syncWithoutDetaching: agrega destinatarios sin borrar los existentes
        $notification->recipients()->syncWithoutDetaching(
            collect($data['user_ids'])->mapWithKeys(fn ($id) => [$id => []])->all()
        );

        return $notification;
    }
}
