<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cubre StoreVetRequest::rules() a través de POST /api/v1/admin/vets, en particular
 * el contactValueFormatRule() de ValidatesContactsArray wireado en contacts.*.value.
 */
class AdminVetControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Country $country;
    private DocumentType $documentType;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(
            ['name' => 'vets.create', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString()],
        );

        $role = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_PLATFORM],
        );
        $role->givePermissionTo('vets.create');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');

        $this->country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'AR',
            'phone_prefix' => '+54',
        ]);

        // validation_regex permisivo: el foco de este test es contactValueFormatRule(),
        // no la validación de tax_id contra el DocumentType.
        $this->documentType = DocumentType::create([
            'guid'             => Str::uuid()->toString(),
            'country_id'       => $this->country->id,
            'name'             => 'CUIT',
            'validation_regex' => '.*',
        ]);
    }

    private function basePayload(array $contacts): array
    {
        return [
            'name'                => 'Vet Test',
            'country_guid'        => $this->country->guid,
            'document_type_guid'  => $this->documentType->guid,
            'tax_id'              => '20-12345678-9',
            'contacts'            => $contacts,
        ];
    }

    // -------------------------------------------------------------------------
    // contactValueFormatRule() — phone/whatsapp deben cumplir el formato E.164
    // -------------------------------------------------------------------------

    public function test_store_rejects_invalid_phone_contact_values(): void
    {
        $invalidValues = [
            '011 15-3429-0838', // espacios y guiones
            '0111534290838',    // cero inicial
            'abc',              // no numérico
        ];

        foreach ($invalidValues as $value) {
            $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/vets', $this->basePayload([
                ['type' => 'phone', 'value' => $value],
            ]));

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['contacts.0.value']);
        }
    }

    public function test_store_accepts_valid_e164_phone_contact_values(): void
    {
        $validValues = [
            '+5491134290838', // con +
            '5491134290838',  // sin + (opcional por diseño)
        ];

        foreach ($validValues as $value) {
            $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/vets', $this->basePayload([
                ['type' => 'whatsapp', 'value' => $value],
            ]));

            $response->assertStatus(201);
            $this->assertDatabaseHas('contacts', ['value' => $value, 'type' => 'whatsapp']);
        }
    }

    public function test_store_accepts_email_contact_without_phone_format_check(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/vets', $this->basePayload([
            ['type' => 'email', 'value' => 'contacto@vet-test.com'],
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('contacts', ['value' => 'contacto@vet-test.com', 'type' => 'email']);
    }

    public function test_store_validates_phone_format_using_matching_array_index_in_multi_item_contacts(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/vets', $this->basePayload([
            ['type' => 'email', 'value' => 'contacto@vet-test.com'],
            ['type' => 'whatsapp', 'value' => 'abc'],
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['contacts.1.value'])
            ->assertJsonMissingValidationErrors(['contacts.0.value']);
    }
}
