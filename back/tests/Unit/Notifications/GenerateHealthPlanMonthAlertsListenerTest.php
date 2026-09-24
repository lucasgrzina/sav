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
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;
use App\Repositories\EstablishmentHealthPlanRepositoryEloquent;
use App\Services\EstablishmentHealthPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RF-01: instanciar un EstablishmentHealthPlan dispara (síncronamente, dentro de la misma
 * transacción — DEC2-01) el listener que genera una Alert HealthPlanMonth por cada mes
 * distinto con actividades materializadas. Se ejercita a través del Service real (no se
 * llama al listener a mano) para cubrir el disparo del evento igual que ProgramAlertGenerationTest.
 */
class GenerateHealthPlanMonthAlertsListenerTest extends TestCase
{
    use RefreshDatabase;

    private EstablishmentHealthPlanService $service;
    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private HealthPlanTemplate $template;
    private int $isoCodeCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EstablishmentHealthPlanService(new EstablishmentHealthPlanRepositoryEloquent());

        $this->vet = $this->createVet();
        $this->client = $this->createClient();
        $this->establishment = Establishment::create([
            'guid' => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name' => 'Establecimiento Test',
        ]);

        $category = HealthPlanCategory::create(['guid' => Str::uuid()->toString(), 'name' => 'Categoria Test']);
        $this->template = HealthPlanTemplate::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Plan Test',
            'health_plan_category_id' => $category->id,
        ]);
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

    private function createClient(): Client
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina Cliente',
            'iso_code' => $this->nextIsoCode(), 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id,
            'name' => 'CUIT', 'validation_regex' => '.*',
        ]);

        return Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente Test',
            'country_id' => $country->id, 'document_type_id' => $documentType->id,
            'tax_id' => '20-' . Str::random(8) . '-9',
        ]);
    }

    private function createProfile(string $roleName): UserProfile
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
        $user = User::factory()->create();

        return UserProfile::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $this->vet->id,
            'role_id' => $role->id,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'establishment_id' => $this->establishment->id,
            'client_id' => $this->client->id,
            'health_plan_template_id' => $this->template->id,
            'year' => now()->year + 10, // bien futuro: nunca cae en el descarte por fecha pasada
        ], $overrides);
    }

    public function test_creates_one_alert_per_distinct_month_with_activities(): void
    {
        $this->createProfile('vet');

        $activityA = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $activityB = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Desparasitación']);
        $this->template->activities()->sync([
            $activityA->id => ['months' => [1, 6], 'sort_order' => 0],
            $activityB->id => ['months' => [6], 'sort_order' => 1],
        ]);

        $plan = $this->service->create($this->basePayload(), $this->vet->id, null);

        $alerts = Alert::where('type', AlertType::HealthPlanMonth)->get();

        // meses distintos con actividades: 1 y 6 => 2 Alert (no una por actividad, DU2-01).
        $this->assertCount(2, $alerts);
        $this->assertEqualsCanonicalizing([1, 6], $alerts->pluck('payload.month')->all());

        foreach ($alerts as $alert) {
            $this->assertSame('establishment_health_plan', $alert->subject_type);
            $this->assertSame($plan->id, $alert->subject_id);
            $this->assertSame($this->vet->id, $alert->vet_id);
            $this->assertSame(array_keys($alert->payload), ['month']); // payload minimalista, DEC2-03
        }
    }

    public function test_scheduled_at_is_seven_days_before_the_month_at_16h(): void
    {
        $this->createProfile('vet');

        $activity = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $this->template->activities()->sync([$activity->id => ['months' => [8], 'sort_order' => 0]]);

        $year = now()->year + 10;
        $this->service->create($this->basePayload(['year' => $year]), $this->vet->id, null);

        $alert = Alert::where('type', AlertType::HealthPlanMonth)->firstOrFail();

        // El cliente de este test no es AR (iso_code genérico -> HealthPlanYear::startMonth
        // default 1), así que el año calendario de "mes 8" coincide con $year — el cálculo de
        // año ganadero por país ya está cubierto en EstablishmentHealthPlanServiceTest; acá
        // solo importa el delta "-7 días, 16hs" que aplica el listener sobre esa fecha.
        $this->assertSame(
            \Carbon\Carbon::create($year, 8, 1)->subDays(7)->setTime(16, 0)->toDateTimeString(),
            $alert->scheduled_at->toDateTimeString(),
        );
    }

    public function test_a_month_whose_computed_date_already_passed_is_silently_discarded(): void
    {
        $this->createProfile('vet');

        $activity = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $this->template->activities()->sync([$activity->id => ['months' => [1], 'sort_order' => 0]]);

        // Año bien pasado: la fecha calculada (mes-7 días, 16hs) siempre queda en el pasado.
        $this->service->create($this->basePayload(['year' => 1990]), $this->vet->id, null);

        $this->assertSame(0, Alert::where('type', AlertType::HealthPlanMonth)->count());
    }

    public function test_no_alert_is_created_when_the_plan_has_no_materialized_activities(): void
    {
        $this->createProfile('vet');

        // Template sin actividades sincronizadas: 0 filas de EstablishmentHealthPlanActivity.
        $this->service->create($this->basePayload(), $this->vet->id, null);

        $this->assertSame(0, Alert::where('type', AlertType::HealthPlanMonth)->count());
    }

    public function test_recipients_are_only_profiles_with_the_vet_role(): void
    {
        $vetProfile = $this->createProfile('vet');
        $this->createProfile('vet-assistant');
        $this->createProfile('client-owner');

        $activity = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $this->template->activities()->sync([$activity->id => ['months' => [1], 'sort_order' => 0]]);

        $this->service->create($this->basePayload(), $this->vet->id, null);

        $alert = Alert::where('type', AlertType::HealthPlanMonth)->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$vetProfile->id],
            $alert->recipients->pluck('user_profile_id')->unique()->all(),
        );
    }

    public function test_no_alert_is_created_when_the_vet_has_no_profile_with_the_vet_role(): void
    {
        $this->createProfile('vet-assistant');

        $activity = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $this->template->activities()->sync([$activity->id => ['months' => [1], 'sort_order' => 0]]);

        $this->service->create($this->basePayload(), $this->vet->id, null);

        $this->assertSame(0, Alert::where('type', AlertType::HealthPlanMonth)->count());
    }

    public function test_recipients_default_to_the_whatsapp_channel(): void
    {
        $vetProfile = $this->createProfile('vet');

        $activity = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $this->template->activities()->sync([$activity->id => ['months' => [1], 'sort_order' => 0]]);

        $this->service->create($this->basePayload(), $this->vet->id, null);

        $alert = Alert::where('type', AlertType::HealthPlanMonth)->firstOrFail();
        $recipient = $alert->recipients->firstOrFail();

        $this->assertSame($vetProfile->id, $recipient->user_profile_id);
        $this->assertSame(Channel::Whatsapp, $recipient->channel);
    }
}
