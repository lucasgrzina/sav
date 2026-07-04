<?php

namespace App\Contracts\Repositories;

use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface NotificationRepositoryInterface
{
    public function findByGuid(string $guid): ?Notification;

    /**
     * Lista paginada de notificaciones del usuario, con su estado de lectura.
     *
     * @param  int    $userId  ID interno del usuario autenticado
     * @param  array  $params  Filtros: per_page, page
     * @return array{ notifications: LengthAwarePaginator, unread_count: int }
     */
    public function list(int $userId, array $params): array;

    /**
     * Últimas N notificaciones del usuario para el dropdown de la campana.
     *
     * @param  int  $userId
     * @param  int  $limit  Default: 10
     * @return array{ notifications: array<Notification>, unread_count: int }
     */
    public function latest(int $userId, int $limit = 10): array;

    /**
     * Cuenta notificaciones no leídas del usuario.
     */
    public function getUnreadCount(int $userId): int;

    /**
     * Crea una notificación con sus destinatarios.
     *
     * @param  array  $data  { payload: array, user_ids: int[], created_by: int|null }
     */
    public function create(array $data): Notification;
}
