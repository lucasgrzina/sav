<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\IndexNotificationRequest;
use App\Http\Requests\Notifications\MarkAsReadRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * GET /v1/notifications
     * Lista paginada de notificaciones del usuario autenticado.
     */
    public function index(IndexNotificationRequest $request): JsonResponse
    {
        try {
            $result    = $this->notificationService->list($request->user()->id, $request->validated());
            $paginator = $result['notifications'];

            return $this->makeSuccess([
                'notifications' => [
                    'data'         => NotificationResource::collection($paginator->items()),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                ],
                'unread_count' => $result['unread_count'],
            ]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/notifications/latest
     * Últimas 10 notificaciones + unread_count para el dropdown de la campana.
     */
    public function latest(Request $request): JsonResponse
    {
        try {
            $result = $this->notificationService->latest($request->user()->id);

            return $this->makeSuccess([
                'notifications' => NotificationResource::collection($result['notifications']),
                'unread_count'  => $result['unread_count'],
            ]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * PATCH /v1/notifications/{guid}/read
     * Marca una notificación específica como leída para el usuario autenticado.
     */
    public function markAsRead(MarkAsReadRequest $request, string $guid): JsonResponse
    {
        try {
            $notification = $this->notificationService->findByGuid($guid);

            if (! $notification) {
                return $this->makeNotFound('Notificación no encontrada.');
            }

            $this->notificationService->markAsRead($guid, $request->user()->id);

            return $this->makeSuccess(null, 'Notificación marcada como leída.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * PATCH /v1/notifications/read-all
     * Marca todas las notificaciones no leídas del usuario como leídas.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $this->notificationService->markAllAsRead($request->user()->id);

            return $this->makeSuccess(null, 'Todas las notificaciones marcadas como leídas.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
