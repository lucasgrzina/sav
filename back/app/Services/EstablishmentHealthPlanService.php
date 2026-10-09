<?php

namespace App\Services;

use App\Contracts\Repositories\EstablishmentHealthPlanRepositoryInterface;
use App\Events\EstablishmentHealthPlanCancelledEvent;
use App\Events\EstablishmentHealthPlanInstantiatedEvent;
use App\Exceptions\EstablishmentHealthPlanActivityConfirmationNotAllowedException;
use App\Exceptions\EstablishmentHealthPlanAlreadyExistsException;
use App\Exceptions\EstablishmentHealthPlanNotEditableException;
use App\Models\Establishment;
use App\Models\EstablishmentHealthPlan;
use App\Models\EstablishmentHealthPlanActivity;
use App\Models\HealthPlanTemplate;
use App\Models\UserProfile;
use App\Support\HealthPlanYear;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EstablishmentHealthPlanService
{
    public function __construct(
        private EstablishmentHealthPlanRepositoryInterface $repository,
        private EstablishmentService $establishmentService,
    ) {}

    public function paginateForVet(int $vetId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repository->paginateForVet($vetId, $filters, $perPage);
    }

    public function findByGuidForVet(string $guid, int $vetId): ?EstablishmentHealthPlan
    {
        return $this->repository->findByGuidForVet($guid, $vetId);
    }

    /**
     * @param array $data {establishment_id, client_id, health_plan_template_id (ints resueltos), year}
     * @throws EstablishmentHealthPlanAlreadyExistsException
     */
    public function create(array $data, int $vetId, ?int $createdByUserId): EstablishmentHealthPlan
    {
        return DB::transaction(function () use ($data, $vetId, $createdByUserId) {
            // Recheck de condición de carrera — la validación principal ya corrió en
            // StoreEstablishmentHealthPlanRequest::withValidator (DEC-04). lockForUpdate
            // toma un lock de rango sobre ehp_dedup_lookup_idx (establishment_id,
            // health_plan_template_id, year), cerrando la ventana bajo REPEATABLE READ
            // entre dos instanciaciones concurrentes de la misma tupla.
            if ($this->repository->existsActiveFor($data['establishment_id'], $data['health_plan_template_id'], $data['year'], lockForUpdate: true)) {
                throw new EstablishmentHealthPlanAlreadyExistsException();
            }

            $establishment = Establishment::with('client.country')->findOrFail($data['establishment_id']);
            $country        = $establishment->client->country;

            [$startsOn, $endsOn] = HealthPlanYear::range($country, $data['year']);

            $plan = $this->repository->create([
                'vet_id'                  => $vetId,
                'client_id'               => $data['client_id'],
                'establishment_id'        => $data['establishment_id'],
                'health_plan_template_id' => $data['health_plan_template_id'],
                'year'                    => $data['year'],
                'starts_on'               => $startsOn,
                'ends_on'                 => $endsOn,
                'created_by_user_id'      => $createdByUserId,
            ]);

            $template = HealthPlanTemplate::with('activities')->findOrFail($data['health_plan_template_id']);

            $rows = [];
            $now  = now();

            foreach ($template->activities as $activity) {
                $months = $activity->pivot->months ?? [];

                foreach ($months as $month) {
                    $rows[] = [
                        'guid'                          => Str::uuid()->toString(),
                        'establishment_health_plan_id'  => $plan->id,
                        'health_activity_id'            => $activity->id,
                        'month'                         => $month,
                        'due_date'                      => HealthPlanYear::dateForMonth($country, $data['year'], $month),
                        'sort_order'                    => $activity->pivot->sort_order ?? 0,
                        'require_confirmation'          => true,
                        'created_at'                    => $now,
                        'updated_at'                    => $now,
                    ];
                }
            }

            if (!empty($rows)) {
                EstablishmentHealthPlanActivity::insert($rows);
            }

            $plan = $plan->fresh()->load(['client.country', 'establishment', 'template.category', 'activities.activity']);

            event(new EstablishmentHealthPlanInstantiatedEvent($plan));

            return $plan;
        });
    }

    /** @throws EstablishmentHealthPlanNotEditableException */
    public function cancel(EstablishmentHealthPlan $plan): EstablishmentHealthPlan
    {
        if (!$plan->editable) {
            throw new EstablishmentHealthPlanNotEditableException();
        }

        return DB::transaction(function () use ($plan) {
            $plan = $this->repository->update($plan, ['cancelled_at' => now()]);

            event(new EstablishmentHealthPlanCancelledEvent($plan));

            return $plan;
        });
    }

    /** @throws EstablishmentHealthPlanActivityConfirmationNotAllowedException */
    public function confirmActivity(EstablishmentHealthPlanActivity $activity, UserProfile $profile): EstablishmentHealthPlanActivity
    {
        if (!in_array($profile->role->name, EstablishmentHealthPlanActivity::CONFIRM_ROLES, true)) {
            throw new EstablishmentHealthPlanActivityConfirmationNotAllowedException();
        }

        // Client staff may only confirm activities of establishments they are linked to.
        if (str_starts_with($profile->role->name, 'client-')) {
            $activity->loadMissing('plan.establishment');

            if (!$this->establishmentService->isProfileLinked($activity->plan->establishment, $profile)) {
                throw new EstablishmentHealthPlanActivityConfirmationNotAllowedException();
            }
        }

        if ($activity->confirmed_at !== null) {
            return $activity; // DEC-09 — idempotente
        }

        $activity->update([
            'confirmed_at'            => now(),
            'confirmed_by_profile_id' => $profile->id,
        ]);

        return $activity->fresh()->load('confirmedBy.user');
    }
}
