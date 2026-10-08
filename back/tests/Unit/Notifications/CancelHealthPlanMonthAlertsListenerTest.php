<?php

namespace Tests\Unit\Notifications;

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
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Repositories\EstablishmentHealthPlanRepositoryEloquent;
use App\Services\EstablishmentHealthPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RF-04 / DU2-04: cancelar un plan borra las Alert HealthPlanMonth pendientes asociadas —
 * y solo esas: no toca alertas ya dispatched/sent ni alertas de otro plan (DEC2-06).
 */
class CancelHealthPlanMonthAlertsListenerTest extends TestCase
{
    use RefreshDatabase;

    private EstablishmentHealthPlanService $service;
    private Vet $vet;
    private HealthPlanTemplate $template;
    private int $isoCodeCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EstablishmentHealthPlanService(new EstablishmentHealthPlanRepositoryEloquent(), new \App\Services\EstablishmentService(new \App\Repositories\EstablishmentRepositoryEloquent(), new \App\Repositories\UserProfileRepositoryEloquent(), new \App\Repositories\ProgramRepositoryEloquent()));
        $this->vet = $this->createVet();

        $category = HealthPlanCategory::create(['guid' => Str::uuid()->toString(), 'name' => 'Categoria Test']);
        $this->template = HealthPlanTemplate::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Plan Test',
            'health_plan_category_id' => $category->id,
        ]);
        $activity = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $this->template->activities()->sync([$activity->id => ['months' => [1, 6], 'sort_order' => 0]]);
    }

    private function nextIsoCode(): string
    {
        $n = $this->isoCodeCounter++;

        return chr(65 + intdiv($n, 26)) . chr(65 + ($n % 26));
    }

    private function createVet(): Vet
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina',
            'iso_code' => $this->nextIsoCode(), 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id,
            'name' => 'CUIT', 'validation_regex' => '.*',
        ]);

        return Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet Test',
            'slug' => 'vet-test-' . Str::random(6), 'country_id' => $country->id,
            'document_type_id' => $documentType->id, 'tax_id' => '20-12345678-9', 'validated_at' => now(),
        ]);
    }

    private function createClientWithEstablishment(): Establishment
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina Cliente ' . Str::random(4),
            'iso_code' => $this->nextIsoCode(), 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id,
            'name' => 'CUIT', 'validation_regex' => '.*',
        ]);
        $client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente Test',
            'country_id' => $country->id, 'document_type_id' => $documentType->id,
            'tax_id' => '20-' . Str::random(8) . '-9',
        ]);

        return Establishment::create([
            'guid' => Str::uuid()->toString(),
            'client_id' => $client->id,
            'name' => 'Establecimiento Test',
        ]);
    }

    private function createVetProfile(): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'vet', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
        $user = User::factory()->create();

        UserProfile::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $this->vet->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_cancelling_a_plan_deletes_its_pending_health_plan_month_alerts(): void
    {
        $this->createVetProfile();
        $establishment = $this->createClientWithEstablishment();

        $plan = $this->service->create([
            'establishment_id' => $establishment->id,
            'client_id' => $establishment->client_id,
            'health_plan_template_id' => $this->template->id,
            'year' => now()->year + 10,
        ], $this->vet->id, null);

        $this->assertSame(2, Alert::where('type', AlertType::HealthPlanMonth)->count());

        $this->service->cancel($plan);

        $this->assertSame(0, Alert::where('type', AlertType::HealthPlanMonth)
            ->where('subject_type', 'establishment_health_plan')
            ->where('subject_id', $plan->id)
            ->count());
    }

    public function test_cancelling_a_plan_does_not_touch_alerts_of_another_plan(): void
    {
        $this->createVetProfile();
        $establishmentA = $this->createClientWithEstablishment();
        $establishmentB = $this->createClientWithEstablishment();

        $planA = $this->service->create([
            'establishment_id' => $establishmentA->id,
            'client_id' => $establishmentA->client_id,
            'health_plan_template_id' => $this->template->id,
            'year' => now()->year + 10,
        ], $this->vet->id, null);

        $planB = $this->service->create([
            'establishment_id' => $establishmentB->id,
            'client_id' => $establishmentB->client_id,
            'health_plan_template_id' => $this->template->id,
            'year' => now()->year + 10,
        ], $this->vet->id, null);

        $this->service->cancel($planA);

        $this->assertSame(0, Alert::where('subject_id', $planA->id)->where('subject_type', 'establishment_health_plan')->count());
        $this->assertSame(2, Alert::where('subject_id', $planB->id)->where('subject_type', 'establishment_health_plan')->count());
    }

    public function test_cancelling_a_plan_does_not_touch_alerts_that_are_no_longer_pending(): void
    {
        $this->createVetProfile();
        $establishment = $this->createClientWithEstablishment();

        $plan = $this->service->create([
            'establishment_id' => $establishment->id,
            'client_id' => $establishment->client_id,
            'health_plan_template_id' => $this->template->id,
            'year' => now()->year + 10,
        ], $this->vet->id, null);

        $alreadyDispatched = Alert::where('type', AlertType::HealthPlanMonth)->firstOrFail();
        $alreadyDispatched->update(['status' => 'dispatched']);

        $this->service->cancel($plan);

        $this->assertDatabaseHas('alerts', ['id' => $alreadyDispatched->id]);
        $this->assertSame(1, Alert::where('subject_id', $plan->id)->where('subject_type', 'establishment_health_plan')->count());
    }
}
