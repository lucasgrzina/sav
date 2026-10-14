<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\ProgramAlertRetryException;
use App\Exceptions\ProgramNotEditableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\IndexProgramAlertsRequest;
use App\Http\Resources\V1\ProgramAlertLineResource;
use App\Services\ProgramAlerts\ProgramAlertRetryService;
use App\Services\ProgramAlerts\ProgramAlertsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Delivery view of a program: what was / will be sent, to whom, and what went wrong. */
class ProgramAlertController extends Controller
{
    public function __construct(
        private ProgramAlertsService $alertsService,
        private ProgramAlertRetryService $retryService,
    ) {}

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

    /** Manual resend of one failed attempt. 422 carries a stable `reason`; 404 when it is not of this program/tenant. */
    public function retry(Request $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $program = $this->alertsService->findProgram((string) $request->route('guid'), $vet->id);

            if ($program === null) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            $recipient = $this->retryService->retry($program, (string) $request->route('recipientGuid'), $vet->id, $request->user()?->id);

            return $this->makeSuccess([
                'recipient_guid' => $recipient->guid,
                'state' => $recipient->status->value,
                'retry_count' => (int) $recipient->retry_count,
                'last_retry_at' => $recipient->last_retry_at?->toISOString(),
            ], 'El aviso se reenviará en instantes.');
        } catch (ProgramAlertRetryException $e) {
            if ($e->reason === ProgramAlertRetryException::NOT_FOUND) {
                return $this->makeNotFound($e->getMessage());
            }

            return $this->makeError(['reason' => $e->reason], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /** "Recalcular avisos": idempotent reconciliation on demand. */
    public function rebuild(Request $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $program = $this->alertsService->findProgram((string) $request->route('guid'), $vet->id);

            if ($program === null) {
                return $this->makeNotFound('Programa no encontrado.');
            }

            $report = $this->alertsService->rebuild($program, $request->user()?->id);

            return $this->makeSuccess([
                'reconciliation' => $report->summary(),
                'warnings' => array_map(fn ($warning) => $warning->toArray(), $report->warnings),
            ]);
        } catch (ProgramNotEditableException $e) {
            return $this->makeError(['reason' => 'not_editable'], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
