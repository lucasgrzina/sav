<?php

namespace Tests\Unit;

use App\Events\EstablishmentHealthPlanCancelledEvent;
use App\Events\EstablishmentHealthPlanInstantiatedEvent;
use App\Exceptions\EstablishmentHealthPlanAlreadyExistsException;
use App\Exceptions\EstablishmentHealthPlanActivityConfirmationNotAllowedException;
use App\Exceptions\EstablishmentHealthPlanNotEditableException;
use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\HealthActivity;
use App\Models\HealthPlanCategory;
use App\Models\HealthPlanTemplate;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Repositories\EstablishmentHealthPlanRepositoryEloquent;
use App\Services\EstablishmentHealthPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstablishmentHealthPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private EstablishmentHealthPlanService $service;
    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private HealthPlanTemplate $template;
    private HealthActivity $activityA;
    private HealthActivity $activityB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EstablishmentHealthPlanService(new EstablishmentHealthPlanRepositoryEloquent());

        $this->vet = $this->createVet();
        $this->client = $this->createClient('AR');
        $this->establishment = Establishment::create([
            'guid'      => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name'      => 'Establecimiento Test',
        ]);

        $category = HealthPlanCategory::create(['guid' => Str::uuid()->toString(), 'name' => 'Categoria Test']);
        $this->template = HealthPlanTemplate::create([
            'guid'                    => Str::uuid()->toString(),
            'name'                    => 'Plan Test',
            'health_plan_category_id' => $category->id,
        ]);

        $this->activityA = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $this->activityB = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Desparasitación']);

        $this->template->activities()->sync([
            $this->activityA->id => ['months' => [1, 6], 'sort_order' => 0],
            $this->activityB->id => ['months' => [3], 'sort_order' => 1],
        ]);
    }

    private int $isoCodeCounter = 0;

    /**
     * countries.iso_code es char(2) unique — un Str::random(2) puede chocar por azar entre
     * dos países creados en el mismo test. Se genera determinísticamente en base 26.
     */
    private function nextIsoCode(): string
    {
        $n = $this->isoCodeCounter++;

        return chr(65 + intdiv($n, 26)) . chr(65 + ($n % 26));
    }

    private function createVet(string $name = 'Vet Test'): Vet
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina ' . $name,
            'iso_code'     => $this->nextIsoCode(),
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid'             => Str::uuid()->toString(),
            'country_id'       => $country->id,
            'name'             => 'CUIT',
            'validation_regex' => '.*',
        ]);

        return Vet::create([
            'guid'             => Str::uuid()->toString(),
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::random(6),
            'country_id'       => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id'           => '20-12345678-9',
            'validated_at'     => now(),
        ]);
    }

    private function createClient(string $isoCode, string $name = 'Cliente Test'): Client
    {
        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Pais ' . $isoCode . Str::random(4),
            'iso_code'     => $isoCode === 'AR' ? 'AR' : $this->nextIsoCode(),
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid'             => Str::uuid()->toString(),
            'country_id'       => $country->id,
            'name'             => 'CUIT',
            'validation_regex' => '.*',
        ]);

        return Client::create([
            'guid'             => Str::uuid()->toString(),
            'name'             => $name,
            'country_id'       => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id'           => '20-' . Str::random(8) . '-9',
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'establishment_id'        => $this->establishment->id,
            'client_id'               => $this->client->id,
            'health_plan_template_id' => $this->template->id,
            'year'                    => 2026,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // create — materialización N actividades x M meses
    // -------------------------------------------------------------------------

    public function test_create_materializes_one_activity_row_per_activity_month_pair(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);

        // activityA: 2 meses, activityB: 1 mes => 3 filas
        $this->assertCount(3, $plan->activities);
    }

    public function test_create_computes_starts_on_ends_on_for_ar_country(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);

        $this->assertEquals('2026-07-01', $plan->starts_on->toDateString());
        $this->assertEquals('2027-06-30', $plan->ends_on->toDateString());
    }

    public function test_create_computes_due_dates_relative_to_ganadero_year(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);

        $dueDatesByMonth = $plan->activities->groupBy('month')->map(fn ($rows) => $rows->first()->due_date->toDateString());

        // mes 1 (enero) < mes de inicio (julio) -> cae en el año calendario siguiente
        $this->assertEquals('2027-01-01', $dueDatesByMonth[1]);
        // mes 3 (marzo) < mes de inicio -> también año siguiente
        $this->assertEquals('2027-03-01', $dueDatesByMonth[3]);
        // mes 6 (junio) < mes de inicio -> también año siguiente
        $this->assertEquals('2027-06-01', $dueDatesByMonth[6]);
    }

    public function test_create_computes_due_dates_for_non_ar_country_within_same_calendar_year(): void
    {
        $mxClient = $this->createClient('MX');
        $mxEstablishment = Establishment::create([
            'guid'      => Str::uuid()->toString(),
            'client_id' => $mxClient->id,
            'name'      => 'Establecimiento MX',
        ]);

        $plan = $this->service->create($this->basePayload([
            'establishment_id' => $mxEstablishment->id,
            'client_id'        => $mxClient->id,
        ]), $this->vet->id, null);

        $dueDatesByMonth = $plan->activities->groupBy('month')->map(fn ($rows) => $rows->first()->due_date->toDateString());

        $this->assertEquals('2026-01-01', $dueDatesByMonth[1]);
        $this->assertEquals('2026-03-01', $dueDatesByMonth[3]);
        $this->assertEquals('2026-06-01', $dueDatesByMonth[6]);
    }

    public function test_snapshot_does_not_change_if_template_is_edited_after_instantiation(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);
        $countBefore = $plan->activities->count();

        // Editar el template después de instanciar: agregar un mes nuevo a activityA
        $this->template->activities()->updateExistingPivot($this->activityA->id, ['months' => [1, 6, 9]]);

        $plan->refresh()->load('activities');

        $this->assertCount($countBefore, $plan->activities);
    }

    // -------------------------------------------------------------------------
    // duplicados (DEC-04)
    // -------------------------------------------------------------------------

    public function test_create_throws_when_active_duplicate_exists(): void
    {
        $this->service->create($this->basePayload(), $this->vet->id, null);

        $this->expectException(EstablishmentHealthPlanAlreadyExistsException::class);

        $this->service->create($this->basePayload(), $this->vet->id, null);
    }

    public function test_create_allows_reinstantiation_after_cancel(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);
        $this->service->cancel($plan);

        $newPlan = $this->service->create($this->basePayload(), $this->vet->id, null);

        $this->assertNotEquals($plan->guid, $newPlan->guid);
    }

    /**
     * Regresión sobre el fix de condición de carrera: el recheck dentro de
     * create() ahora pide existsActiveFor(..., lockForUpdate: true). El motor
     * de tests corre sobre SQLite (compileLock() es no-op ahí — ver
     * SQLiteGrammar), así que esto no puede probar el bloqueo real de InnoDB
     * bajo REPEATABLE READ; lo que sí garantiza es que el flag no altera el
     * resultado de la consulta ni rompe el flujo de creación/duplicado.
     */
    public function test_exists_active_for_with_lock_for_update_returns_same_result_as_without_it(): void
    {
        $repository = new EstablishmentHealthPlanRepositoryEloquent();

        $this->assertFalse($repository->existsActiveFor(
            $this->establishment->id,
            $this->template->id,
            2026,
            lockForUpdate: true,
        ));

        $this->service->create($this->basePayload(), $this->vet->id, null);

        $this->assertTrue($repository->existsActiveFor(
            $this->establishment->id,
            $this->template->id,
            2026,
            lockForUpdate: true,
        ));

        $this->expectException(EstablishmentHealthPlanAlreadyExistsException::class);
        $this->service->create($this->basePayload(), $this->vet->id, null);
    }

    // -------------------------------------------------------------------------
    // eventos de dominio (Fase 2 — DEC2-01/DEC2-06)
    // -------------------------------------------------------------------------

    public function test_create_dispatches_the_instantiated_event(): void
    {
        // Fake acotado al evento propio: un Event::fake() sin argumentos también reemplaza
        // el dispatcher de Eloquent (Event::fake() llama a Model::setEventDispatcher($fake)),
        // lo que rompe el hook 'creating' de HasGuid y el guid NOT NULL de la propia
        // creación que se está probando.
        Event::fake(EstablishmentHealthPlanInstantiatedEvent::class);

        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);

        Event::assertDispatched(
            EstablishmentHealthPlanInstantiatedEvent::class,
            fn (EstablishmentHealthPlanInstantiatedEvent $event) => $event->plan->id === $plan->id,
        );
    }

    public function test_cancel_dispatches_the_cancelled_event(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);

        Event::fake();

        $cancelled = $this->service->cancel($plan);

        Event::assertDispatched(
            EstablishmentHealthPlanCancelledEvent::class,
            fn (EstablishmentHealthPlanCancelledEvent $event) => $event->plan->id === $cancelled->id,
        );
    }

    // -------------------------------------------------------------------------
    // cancel
    // -------------------------------------------------------------------------

    public function test_cancel_marks_cancelled_at(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);

        $cancelled = $this->service->cancel($plan);

        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertFalse($cancelled->fresh()->editable);
    }

    public function test_cancel_on_already_cancelled_plan_throws_exception(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);
        $this->service->cancel($plan);

        $this->expectException(EstablishmentHealthPlanNotEditableException::class);

        $this->service->cancel($plan->fresh());
    }

    // -------------------------------------------------------------------------
    // confirmActivity (DU-07 / DEC-06 / DEC-09)
    // -------------------------------------------------------------------------

    private function createProfile(string $roleName): UserProfile
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT]);
        $user = User::factory()->create(['guid' => Str::uuid()->toString()]);

        return UserProfile::create([
            'guid'                 => Str::uuid()->toString(),
            'user_id'              => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id'   => $this->vet->id,
            'role_id'              => $role->id,
        ]);
    }

    public function test_confirm_activity_sets_confirmed_at_and_confirmed_by(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);
        $activity = $plan->activities->first();
        $profile = $this->createProfile('vet');

        $confirmed = $this->service->confirmActivity($activity, $profile);

        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertEquals($profile->id, $confirmed->confirmed_by_profile_id);
        $this->assertEquals('confirmed', $confirmed->status);
    }

    public function test_confirm_activity_rejects_role_not_in_confirm_roles(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);
        $activity = $plan->activities->first();
        $profile = $this->createProfile('vet-administrative'); // DEC-06: excluido explícitamente

        $this->expectException(EstablishmentHealthPlanActivityConfirmationNotAllowedException::class);

        $this->service->confirmActivity($activity, $profile);
    }

    public function test_confirm_activity_is_idempotent_on_second_confirmation(): void
    {
        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);
        $activity = $plan->activities->first();
        $profileA = $this->createProfile('vet');
        $profileB = $this->createProfile('vet-assistant');

        $first = $this->service->confirmActivity($activity, $profileA);
        $second = $this->service->confirmActivity($first->fresh(), $profileB);

        // El segundo intento (con otro perfil) no pisa quién confirmó primero.
        $this->assertEquals($profileA->id, $second->confirmed_by_profile_id);
    }
}
