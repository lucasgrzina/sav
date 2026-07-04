<?php

namespace App\Http\Middleware;

use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Contracts\Repositories\VetRepositoryInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserBelongsToVet
{
    public function __construct(
        private VetRepositoryInterface         $vetRepository,
        private UserProfileRepositoryInterface $userProfileRepository,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Obtener guid del parámetro de ruta
        $guid = $request->route('vet');

        if (!$guid) {
            return response()->json(['success' => false, 'message' => 'Tenant no especificado.'], 403);
        }

        // 2. Resolver la veterinaria por guid
        $vet = $this->vetRepository->findByGuid($guid);

        if (!$vet) {
            return response()->json(['success' => false, 'message' => 'Veterinaria no encontrada.'], 404);
        }

        // 3. Verificar que la vet está activa (validada y no suspendida)
        if (!$vet->validated_at || $vet->suspended_at) {
            return response()->json(['success' => false, 'message' => 'Veterinaria inactiva.'], 403);
        }

        // 4. Verificar que el usuario tiene un UserProfile en esta vet
        $user    = $request->user();
        $profile = $this->userProfileRepository->findForUserAndVet($user, $vet);

        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'Sin acceso a esta veterinaria.'], 403);
        }

        if ($profile->blocked_at) {
            return response()->json(['success' => false, 'message' => 'Tu acceso a esta veterinaria está bloqueado.'], 403);
        }

        // 5. Compartir el vet y el profile resueltos con la request
        //    para que los controllers los usen sin volver a buscarlos
        $request->attributes->set('current_vet', $vet);
        $request->attributes->set('current_profile', $profile);

        return $next($request);
    }
}
