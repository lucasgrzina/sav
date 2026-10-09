<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\EstablishmentHealthPlanActivityConfirmationNotAllowedException;
use App\Exceptions\EstablishmentHealthPlanAlreadyExistsException;
use App\Exceptions\EstablishmentHealthPlanNotEditableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\EstablishmentHealthPlans\ConfirmEstablishmentHealthPlanActivityRequest;
use App\Http\Requests\EstablishmentHealthPlans\IndexEstablishmentHealthPlanRequest;
use App\Http\Requests\EstablishmentHealthPlans\StoreEstablishmentHealthPlanRequest;
use App\Http\Resources\V1\EstablishmentHealthPlanActivityResource;
use App\Http\Resources\V1\EstablishmentHealthPlanListResource;
use App\Http\Resources\V1\EstablishmentHealthPlanResource;
use App\Models\Establishment;
use App\Models\HealthPlanTemplate;
use App\Services\EstablishmentHealthPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablishmentHealthPlanController extends Controller
{
    public function __construct(private EstablishmentHealthPlanService $service) {}

    public function index(IndexEstablishmentHealthPlanRequest $request): JsonResponse
    {
        try {
            $vet = $request->attributes->get('current_vet');
            $filters = [
                'client_guid'        => $request->query('client_id'),
                'establishment_guid' => $request->query('establishment_id'),
                'cancelled'          => $request->has('cancelled') ? $request->boolean('cancelled') : null,
                'search'             => $request->query('search'),
            ];
            $perPage   = $request->integer('per_page', 15);
            $paginator = $this->service->paginateForVet($vet->id, $filters, $perPage);

            return $this->makeSuccessPagination($paginator, EstablishmentHealthPlanListResource::class);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreEstablishmentHealthPlanRequest $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $data = $this->resolveGuidsToIds($request->validated());
            $plan = $this->service->create($data, $vet->id, $request->user()?->id);

            return $this->makeSuccess(new EstablishmentHealthPlanResource($plan), 'Plan sanitario creado correctamente.', 201);
        } catch (EstablishmentHealthPlanAlreadyExistsException $e) {
            return $this->makeError(['reason' => 'duplicate_plan'], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $plan = $this->service->findByGuidForVet($guid, $vet->id);

            if (!$plan) {
                return $this->makeNotFound('Plan sanitario no encontrado.');
            }

            return $this->makeSuccess(new EstablishmentHealthPlanResource($plan));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $guid = $request->route('guid');
            $plan = $this->service->findByGuidForVet($guid, $vet->id);

            if (!$plan) {
                return $this->makeNotFound('Plan sanitario no encontrado.');
            }

            $plan = $this->service->cancel($plan);

            return $this->makeSuccess(new EstablishmentHealthPlanResource($plan), 'Plan sanitario cancelado correctamente.');
        } catch (EstablishmentHealthPlanNotEditableException $e) {
            return $this->makeError(['reason' => 'not_editable'], $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function confirmActivity(ConfirmEstablishmentHealthPlanActivityRequest $request): JsonResponse
    {
        try {
            $vet  = $request->attributes->get('current_vet');
            $plan = $this->service->findByGuidForVet($request->route('guid'), $vet->id);

            if (!$plan) {
                return $this->makeNotFound('Plan sanitario no encontrado.');
            }

            $activity = $plan->activities->firstWhere('guid', $request->route('activityGuid'));

            if (!$activity) {
                return $this->makeNotFound('Actividad no encontrada.');
            }

            $profile  = $request->attributes->get('current_profile');
            $activity = $this->service->confirmActivity($activity, $profile);

            return $this->makeSuccess(new EstablishmentHealthPlanActivityResource($activity), 'Actividad confirmada correctamente.');
        } catch (EstablishmentHealthPlanActivityConfirmationNotAllowedException $e) {
            return $this->makeError(['reason' => 'role_not_allowed'], $e->getMessage(), 403);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    private function resolveGuidsToIds(array $data): array
    {
        $establishment = Establishment::where('guid', $data['establishment_id'])->first();

        $data['establishment_id']        = $establishment?->id;
        $data['client_id']               = $establishment?->client_id;
        $data['health_plan_template_id'] = HealthPlanTemplate::where('guid', $data['health_plan_template_id'])->value('id');

        return $data;
    }
}
