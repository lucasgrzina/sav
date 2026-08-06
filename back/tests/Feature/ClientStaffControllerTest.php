<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cubre AssignClientStaffRequest::rules() a través de POST
 * /api/v1/vets/{vet}/clients/{client}/staff — lado Client/Member de
 * ValidatesContactsArray::contactValueFormatRule().
 */
class ClientStaffControllerTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private Client $client;
    private User $actor;
    private Role $clientOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['clients.staff.read', 'clients.staff.create'] as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $vetRole = Role::firstOrCreate(
            ['name' => 'vet', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
        $vetRole->givePermissionTo(['clients.staff.read', 'clients.staff.create']);

        $this->clientOwnerRole = Role::firstOrCreate(
            ['name' => 'client-owner', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );

        $country = Country::create([
            'guid'         => Str::uuid()->toString(),
            'name'         => 'Argentina',
            'iso_code'     => 'AR',
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid'             => Str::uuid()->toString(),
            'country_id'       => $country->id,
            'name'             => 'CUIT',
            'validation_regex' => '.*',
        ]);

        $this->vet = Vet::create([
            'guid'             => Str::uuid()->toString(),
            'name'             => 'Vet Test',
            'slug'             => Str::slug('Vet Test') . '-' . Str::random(6),
            'country_id'       => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id'           => '20-12345678-9',
            'validated_at'     => now(),
        ]);

        $this->client = Client::create([
            'guid'             => Str::uuid()->toString(),
            'name'             => 'Cliente Test',
            'country_id'       => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id'           => '20-98765432-1',
        ]);
        $this->client->vets()->attach($this->vet);

        $this->actor = User::factory()->create();
        UserProfile::create([
            'guid'                 => Str::uuid()->toString(),
            'user_id'              => $this->actor->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id'   => $this->vet->id,
            'role_id'              => $vetRole->id,
        ]);
    }

    private function basePayload(User $targetUser, array $contacts): array
    {
        return [
            'user_guid' => $targetUser->guid,
            'role_guid' => $this->clientOwnerRole->guid,
            'contacts'  => $contacts,
        ];
    }

    private function endpoint(): string
    {
        return "/api/v1/vets/{$this->vet->guid}/clients/{$this->client->guid}/staff";
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
            $targetUser = User::factory()->create();

            $response = $this->actingAs($this->actor, 'sanctum')->postJson($this->endpoint(), $this->basePayload($targetUser, [
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
            $targetUser = User::factory()->create();

            $response = $this->actingAs($this->actor, 'sanctum')->postJson($this->endpoint(), $this->basePayload($targetUser, [
                ['type' => 'whatsapp', 'value' => $value],
            ]));

            $response->assertStatus(201);
            $this->assertDatabaseHas('contacts', ['value' => $value, 'type' => 'whatsapp']);
        }
    }

    public function test_store_accepts_email_contact_without_phone_format_check(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->actingAs($this->actor, 'sanctum')->postJson($this->endpoint(), $this->basePayload($targetUser, [
            ['type' => 'email', 'value' => 'staff@cliente-test.com'],
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('contacts', ['value' => 'staff@cliente-test.com', 'type' => 'email']);
    }

    public function test_store_validates_phone_format_using_matching_array_index_in_multi_item_contacts(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->actingAs($this->actor, 'sanctum')->postJson($this->endpoint(), $this->basePayload($targetUser, [
            ['type' => 'email', 'value' => 'staff@cliente-test.com'],
            ['type' => 'whatsapp', 'value' => 'abc'],
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['contacts.1.value'])
            ->assertJsonMissingValidationErrors(['contacts.0.value']);
    }
}
