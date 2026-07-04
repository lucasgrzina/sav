<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportMessages\CreateSupportMessageRequest;
use App\Http\Requests\SupportMessages\CreateReplyRequest;
use App\Http\Resources\V1\SupportMessageResource;
use App\Http\Resources\V1\SupportMessageReplyResource;
use App\Services\SupportMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportMessageController extends Controller
{
    public function __construct(
        private SupportMessageService $supportMessageService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->get('per_page', 15);
            $paginator = $this->supportMessageService->list($request->user(), $perPage);

            return $this->makeSuccessPagination($paginator, SupportMessageResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(CreateSupportMessageRequest $request): JsonResponse
    {
        try {
            $message = $this->supportMessageService->create($request->user(), $request->validated());

            return $this->makeSuccess(new SupportMessageResource($message), 'Mensaje enviado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(Request $request, string $guid): JsonResponse
    {
        try {
            $message = $this->supportMessageService->findByGuidWithReplies($guid);

            if (! $message) {
                return $this->makeNotFound('Mensaje no encontrado.');
            }

            if ($message->user_id !== $request->user()->id && ! $request->user()->can('support-messages.read')) {
                return $this->makeError(null, 'No tenés permiso para ver este mensaje.', 403);
            }

            return $this->makeSuccess(new SupportMessageResource($message));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request, string $guid): JsonResponse
    {
        try {
            $message = $this->supportMessageService->findByGuid($guid);

            if (! $message) {
                return $this->makeNotFound('Mensaje no encontrado.');
            }

            if ($message->user_id !== $request->user()->id) {
                return $this->makeError(null, 'No tenés permiso para eliminar este mensaje.', 403);
            }

            if ($message->status !== 'open') {
                return $this->makeError(null, 'Solo podés eliminar mensajes con estado abierto.', 422);
            }

            $this->supportMessageService->destroy($message);

            return $this->makeSuccess(null, 'Mensaje eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function addReply(CreateReplyRequest $request, string $guid): JsonResponse
    {
        try {
            $message = $this->supportMessageService->findByGuid($guid);

            if (! $message) {
                return $this->makeNotFound('Mensaje no encontrado.');
            }

            $user = $request->user();
            $isOwner = $message->user_id === $user->id;
            $canReply = $user->can('support-messages.reply');

            if (! $isOwner && ! $canReply) {
                return $this->makeError(null, 'No tenés permiso para responder este mensaje.', 403);
            }

            if ($message->status === 'closed') {
                return $this->makeError(null, 'No se puede responder un mensaje cerrado.', 422);
            }

            $reply = $this->supportMessageService->addReply($message, $user, $request->validated());

            return $this->makeSuccess(new SupportMessageReplyResource($reply), 'Respuesta enviada correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function close(Request $request, string $guid): JsonResponse
    {
        try {
            $message = $this->supportMessageService->findByGuid($guid);

            if (! $message) {
                return $this->makeNotFound('Mensaje no encontrado.');
            }

            if (! $request->user()->can('support-messages.close')) {
                return $this->makeError(null, 'No tenés permiso para cerrar este mensaje.', 403);
            }

            if ($message->status === 'closed') {
                return $this->makeError(null, 'El mensaje ya está cerrado.', 422);
            }

            $message = $this->supportMessageService->close($message, $request->user());

            return $this->makeSuccess(new SupportMessageResource($message), 'Mensaje cerrado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
