<?php

namespace Tests\Feature;

use App\Enums\ContactType;
use App\Models\Client;
use App\Models\Contact;
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
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Gateways\Fake\FakeGateway;
use App\Notifications\Models\Alert;
use App\Repositories\EstablishmentHealthPlanRepositoryEloquent;
use App\Services\EstablishmentHealthPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cobertura end-to-end de la Fase 2: instanciar un EstablishmentHealthPlan real genera
 * Alert HealthPlanMonth con destinatarios (RF-01), y el pipeline genérico de despacho
 * (alerts:dispatch-due -> DeliverAlertJob, QUEUE_CONNECTION=sync en testing) suprime el
 * envío cuando todas las actividades del mes se confirman antes de que llegue la hora de
 * envío (RF-02).
 */
class EstablishmentHealthPlanAlertsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private EstablishmentHealthPlanService $service;
    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private HealthPlanTemplate $template;
    private HealthActivity $activity;
    private int $isoCodeCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EstablishmentHealthPlanService(new EstablishmentHealthPlanRepositoryEloquent(), new \App\Services\EstablishmentService(new \App\Repositories\EstablishmentRepositoryEloquent(), new \App\Repositories\UserProfileRepositoryEloquent(), new \App\Repositories\ProgramRepositoryEloquent()));

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
        $this->activity = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación']);
        $this->template->activities()->sync([$this->activity->id => ['months' => [7], 'sort_order' => 0]]);

        $this->createVetProfileWithWhatsappContact();
    }

    /**
     * Único perfil destinatario real de la Alert (el que existe al momento de instanciar el
     * plan — DU2-02). Necesita un contacto habilitado para que DeliverAlertJob::toDto()
     * resuelva el canal y el flujo llegue hasta el HealthPlanMonthMessageBuilder — sin esto,
     * fallaría antes por RecipientContactNotFoundException, no por la supresión que se quiere
     * probar.
     */
    private function createVetProfileWithWhatsappContact(): UserProfile
    {
        $profile = $this->createVetProfile();

        Contact::create([
            'contactable_type' => 'user_profile',
            'contactable_id' => $profile->id,
            'type' => ContactType::Whatsapp,
            'value' => '5491122334455',
            'is_primary' => true,
            'use_for_alerts' => true,
        ]);

        return $profile;
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

    private function createVetProfile(): UserProfile
    {
        $role = Role::firstOrCreate(
            ['name' => 'vet', 'guard_name' => 'web'],
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

    public function test_instantiating_a_plan_creates_a_health_plan_month_alert_with_recipients(): void
    {
        $plan = $this->service->create([
            'establishment_id' => $this->establishment->id,
            'client_id' => $this->client->id,
            'health_plan_template_id' => $this->template->id,
            'year' => now()->year + 10,
        ], $this->vet->id, null);

        $alert = Alert::where('type', AlertType::HealthPlanMonth)->firstOrFail();

        $this->assertSame('establishment_health_plan', $alert->subject_type);
        $this->assertSame($plan->id, $alert->subject_id);
        $this->assertSame(['month' => 7], $alert->payload);
        $this->assertGreaterThan(0, $alert->recipients->count());
    }

    public function test_confirming_all_activities_before_the_send_time_suppresses_the_delivery(): void
    {
        $fakeGateway = new FakeGateway();
        app()->instance(FakeGateway::class, $fakeGateway);

        $plan = $this->service->create([
            'establishment_id' => $this->establishment->id,
            'client_id' => $this->client->id,
            'health_plan_template_id' => $this->template->id,
            'year' => now()->year + 10,
        ], $this->vet->id, null);

        $alert = Alert::where('type', AlertType::HealthPlanMonth)->firstOrFail();
        $alert->update(['scheduled_at' => now()->subMinute()]);

        // Confirmar TODAS las actividades del mes antes de que corra alerts:dispatch-due.
        $confirmingProfile = $this->createVetProfile();
        foreach ($plan->activities()->where('month', 7)->get() as $pendingActivity) {
            $this->service->confirmActivity($pendingActivity, $confirmingProfile);
        }

        Artisan::call('alerts:dispatch-due');

        $alert->refresh();
        $this->assertSame('dispatched', $alert->status);

        foreach ($alert->recipients as $recipient) {
            $this->assertSame(DeliveryStatus::Suppressed, $recipient->status);
            $this->assertSame('no_longer_applicable', $recipient->failure_reason);
        }

        $this->assertCount(0, $fakeGateway->sentMessages());
    }
}
