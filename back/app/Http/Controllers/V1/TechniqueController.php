<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TechniqueResource;
use App\Services\TechniqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechniqueController extends Controller
{
    public function __construct(
        private TechniqueService $techniqueService,
    ) {}

    /**
     * GET /v1/techniques?type=technique|vaccine
     * Retorna jerarquía completa (raíces + hijos) para el selector de técnicas del vet.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $type       = $request->query('type');
            $techniques = $this->techniqueService->listForApi($type);

            return $this->makeSuccess(TechniqueResource::collection($techniques));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/techniques/protocols
     * STUB: Retorna protocolos del vet agrupados por técnica.
     * Implementar cuando exista el módulo de protocolos.
     */
    public function protocols(Request $request): JsonResponse
    {
        try {
            // TODO: Implementar cuando exista el módulo de protocolos
            return $this->makeSuccess([]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/techniques/{guid}/protocols
     * STUB: Retorna protocolos de una técnica específica del vet.
     */
    public function techniqueProtocols(string $guid): JsonResponse
    {
        try {
            $technique = $this->techniqueService->findRootByGuid($guid);

            if (!$technique) {
                return $this->makeNotFound('Técnica no encontrada.');
            }

            // TODO: Implementar cuando exista el módulo de protocolos
            return $this->makeSuccess([]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
