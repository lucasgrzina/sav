<?php

namespace Tests\Unit\Notifications;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\EstablishmentHealthPlan;
use App\Models\HealthActivity;
use App\Models\HealthPlanCategory;
use App\Models\HealthPlanTemplate;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Events\EstablishmentHealthPlanInstantiatedEvent;
use App\Notifications\Builders\HealthPlanMonthMessageBuilder;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\PushContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;
use App\Repositories\EstablishmentHealthPlanRepositoryEloquent;
use App\Services\EstablishmentHealthPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RF-02, pieza central de la Fase 2: el builder recalcula las actividades pendientes
 * contra la BD en el momento del envío (no el payload congelado de la Alert) y retorna
 * null cuando ya no queda ninguna pendiente para ese (plan, mes).
 */
class HealthPlanMonthMessageBuilderTest extends TestCase
{
    use RefreshDatabase;

    private HealthPlanMonthMessageBuilder $builder;
    private EstablishmentHealthPlanService $service;
    private Vet $vet;
    private Establishment $establishment;
    private EstablishmentHealthPlan $plan;
    private HealthActivity $activityA;
    private HealthActivity $activityB;
    private int $isoCodeCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new HealthPlanMonthMessageBuilder();
        $this->service = new EstablishmentHealthPlanService(new EstablishmentHealthPlanRepositoryEloquent(), new \App\Services\EstablishmentService(new \App\Repositories\EstablishmentRepositoryEloquent(), new \App\Repositories\UserProfileRepositoryEloquent(), new \App\Repositories\ProgramRepositoryEloquent()));

        $this->vet = $this->createVet();
        $client = $this->createClient();
        $this->establishment = Establishment::create([
            'guid' => Str::uuid()->toString(),
            'client_id' => $client->id,
            'name' => 'Establecimiento Norte',
        ]);

        $category = HealthPlanCategory::create(['guid' => Str::uuid()->toString(), 'name' => 'Bovinos']);
        $template = HealthPlanTemplate::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Plan Ganadero Anual',
            'health_plan_category_id' => $category->id,
        ]);

        $this->activityA = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Vacunación Aftosa']);
        $this->activityB = HealthActivity::create(['guid' => Str::uuid()->toString(), 'name' => 'Desparasitación']);
        $template->activities()->sync([
            $this->activityA->id => ['months' => [7], 'sort_order' => 0],
            $this->activityB->id => ['months' => [7], 'sort_order' => 1],
        ]);

        // Fake acotado al evento propio: evita que el listener real (ya probado en su propio
        // test) cree Alert reales acá — este test construye la Alert a mano para aislar el
        // builder. Un Event::fake() sin argumentos también reemplaza el dispatcher de
        // Eloquent (Model::setEventDispatcher), rompiendo el hook 'creating' de HasGuid que
        // esta misma creación necesita para el guid NOT NULL.
        Event::fake(EstablishmentHealthPlanInstantiatedEvent::class);

        $this->plan = $this->service->create([
            'establishment_id' => $this->establishment->id,
            'client_id' => $client->id,
            'health_plan_template_id' => $template->id,
            'year' => now()->year + 10,
        ], $this->vet->id, null);
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
            'guid' => Str::uuid()->toString(), 'name' => 'Estancia La Esperanza',
            'country_id' => $country->id, 'document_type_id' => $documentType->id,
            'tax_id' => '20-' . Str::random(8) . '-9',
        ]);
    }

    private function createProfile(string $roleName = 'vet'): UserProfile
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

    private function alertForMonth(int $month): Alert
    {
        $alert = new Alert(['payload' => ['month' => $month]]);
        $alert->guid = Str::uuid()->toString();
        $alert->setRelation('subject', $this->plan);

        return $alert;
    }

    private function recipient(Channel $channel): Recipient
    {
        return new Recipient(userId: 1, phone: '5491122334455', name: 'Lucas', channel: $channel);
    }

    public function test_builds_email_content_with_the_pending_activities_of_the_month(): void
    {
        $content = $this->builder->build($this->alertForMonth(7), $this->recipient(Channel::Email));

        $this->assertInstanceOf(EmailContent::class, $content);
        $this->assertStringContainsString('Establecimiento Norte', $content->subject);
        $this->assertStringContainsString('Vacunación Aftosa', $content->body);
        $this->assertStringContainsString('Desparasitación', $content->body);
        $this->assertStringContainsString('julio', $content->body);
    }

    public function test_builds_push_content_with_alert_and_plan_guids(): void
    {
        $alert = $this->alertForMonth(7);

        $content = $this->builder->build($alert, $this->recipient(Channel::Push));

        $this->assertInstanceOf(PushContent::class, $content);
        $this->assertSame("alert-{$alert->guid}", $content->tag);
        $this->assertSame("establishment-health-plans/{$this->plan->guid}", $content->url);
        $this->assertFalse($content->data['requires_confirmation']);
        $this->assertSame($alert->guid, $content->data['alert_guid']);
        $this->assertSame($this->plan->guid, $content->data['establishment_health_plan_guid']);
    }

    public function test_builds_template_content_with_seven_positional_variables_in_order(): void
    {
        $content = $this->builder->build($this->alertForMonth(7), $this->recipient(Channel::Whatsapp));

        $this->assertInstanceOf(TemplateContent::class, $content);
        $this->assertSame(AlertType::HealthPlanMonth, $content->type);
        $this->assertSame(range(1, 7), array_keys($content->variables));
        $this->assertSame('Lucas', $content->variables[1]);
        $this->assertSame('julio', $content->variables[2]);
        $this->assertStringContainsString('Vacunación Aftosa', $content->variables[3]);
        $this->assertSame('Plan Ganadero Anual', $content->variables[4]);
        $this->assertSame('Bovinos', $content->variables[5]);
        $this->assertSame('Estancia La Esperanza', $content->variables[6]);
        $this->assertSame('Establecimiento Norte', $content->variables[7]);
    }

    public function test_returns_null_when_every_activity_of_the_month_was_already_confirmed(): void
    {
        $profile = $this->createProfile();
        $activities = $this->plan->activities()->where('month', 7)->get();

        foreach ($activities as $activity) {
            $this->service->confirmActivity($activity, $profile);
        }

        $content = $this->builder->build($this->alertForMonth(7), $this->recipient(Channel::Email));

        $this->assertNull($content);
    }

    public function test_only_lists_the_activities_still_pending_when_some_were_confirmed(): void
    {
        $profile = $this->createProfile();
        $confirmedActivity = $this->plan->activities()
            ->where('month', 7)
            ->where('health_activity_id', $this->activityA->id)
            ->firstOrFail();

        $this->service->confirmActivity($confirmedActivity, $profile);

        $content = $this->builder->build($this->alertForMonth(7), $this->recipient(Channel::Email));

        $this->assertInstanceOf(EmailContent::class, $content);
        $this->assertStringNotContainsString('Vacunación Aftosa', $content->body);
        $this->assertStringContainsString('Desparasitación', $content->body);
    }
}
