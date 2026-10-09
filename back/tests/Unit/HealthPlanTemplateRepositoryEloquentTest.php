<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\EstablishmentHealthPlan;
use App\Models\HealthPlanCategory;
use App\Models\HealthPlanTemplate;
use App\Models\Vet;
use App\Repositories\HealthPlanTemplateRepositoryEloquent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HealthPlanTemplateRepositoryEloquentTest extends TestCase
{
    use RefreshDatabase;

    private HealthPlanTemplateRepositoryEloquent $repository;
    private HealthPlanCategory $category;
    private Vet $vet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(HealthPlanTemplateRepositoryEloquent::class);
        $this->category   = HealthPlanCategory::create(['guid' => Str::uuid()->toString(), 'name' => 'Categoria Test']);

        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54',
        ]);
        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*',
        ]);
        $this->vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet Test', 'slug' => 'vet-test-' . Str::random(6),
            'country_id' => $country->id, 'document_type_id' => $documentType->id,
            'tax_id' => '20-12345678-9', 'validated_at' => now(),
        ]);
    }

    public function test_admin_paginate_never_includes_vet_owned_templates(): void
    {
        HealthPlanTemplate::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Global', 'health_plan_category_id' => $this->category->id, 'vet_id' => null,
        ]);
        HealthPlanTemplate::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Propia del vet', 'health_plan_category_id' => $this->category->id, 'vet_id' => $this->vet->id,
        ]);

        $result = $this->repository->paginate([], 50);

        $this->assertCount(1, $result->items());
        $this->assertEquals('Global', $result->items()[0]->name);
    }

    public function test_has_instantiated_plans_detects_usage(): void
    {
        $template = HealthPlanTemplate::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Plan Test', 'health_plan_category_id' => $this->category->id, 'vet_id' => $this->vet->id,
        ]);

        $this->assertFalse($this->repository->hasInstantiatedPlans($template->id));

        $client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente Test',
            'country_id' => $this->vet->country_id, 'document_type_id' => $this->vet->document_type_id, 'tax_id' => '20-99999999-9',
        ]);
        $this->vet->clients()->attach($client->id, ['created_at' => now(), 'updated_at' => now()]);
        $establishment = Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $client->id, 'name' => 'Establecimiento Test']);

        EstablishmentHealthPlan::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $this->vet->id, 'client_id' => $client->id,
            'establishment_id' => $establishment->id, 'health_plan_template_id' => $template->id,
            'year' => 2026, 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30',
        ]);

        $this->assertTrue($this->repository->hasInstantiatedPlans($template->id));
    }
}
