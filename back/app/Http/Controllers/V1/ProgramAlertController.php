<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\IndexProgramAlertsRequest;
use App\Http\Resources\V1\ProgramAlertLineResource;
use App\Services\ProgramAlerts\ProgramAlertsService;
use Illuminate\Http\JsonResponse;

/** Delivery view of a program: what was / will be sent, to whom, and what went wrong. */
class ProgramAlertController extends Controller
{
    public function __construct(private ProgramAlertsService $alertsService) {}

    public function index(IndexProgramAlertsRequest $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $program = $this->alertsService->findProgram((string) $request->route('guid'), $vet->id);

            if ($program === null) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            $result = $this->alertsService->list(
                $program,
                $vet->id,
                [
                    'status'  => $request->query('status', 'all'),
                    'profile' => $request->query('profile'),
                    'target'  => $request->query('target'),
                    'type'    => $request->query('type'),
                ],
                $request->integer('page', 1),
                $request->integer('per_page', 25),
            );

            return $this->makeSuccessPagination($result['paginator'], ProgramAlertLineResource::class, $result['meta']);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
