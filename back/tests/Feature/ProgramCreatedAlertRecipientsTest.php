<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Permission;
use App\Models\Protocol;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Smoke test end-to-end: crear un Program real vía la API dispara ProgramCreatedEvent, que
 * pasa por ScheduleProgramCreatedAlertListener → AlertRecipientFactory (DEC-08/DEC-09) y
 * genera tanto el canal Whatsapp como el canal Push cuando el manager tiene una suscripción
 * push activa.
 */
class ProgramCreatedAlertRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private Protocol $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('notifications.default_channels', [Channel::Whatsapp, Channel::Push]);

        $permissions = ['programs.read', 'programs.create', 'programs.update'];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        Role::firstOrCreate(
            ['name' => 'vet', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );

        Role::where('name', 'vet')->first()->givePermissionTo(Permission::whereIn('name', $permissions)->get());

        $this->vet = $this->createVet();
        $this->client = $this->createClient();
        $this->vet->clients()->attach($this->client->id, ['created_at' => now(), 'updated_at' => now()]);
        $this->establishment = Establishment::create([
            'guid' => Str::uuid()->toString(),
            'client_id' => $this->client->id,
            'name' => 'Establecimiento Test',
        ]);

        $root = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $technique = Technique::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'IATF',
            'type' => 'technique',
            'parent_id' => $root->id,
        ]);

        $this->protocol = Protocol::create([
            'guid' => Str::uuid()->toString(),
            'technique_id' => $technique->id,
            'vet_id' => $this->vet->id,
            'created_by_type' => 'vet',
            'created_by_id' => 1,
            'name' => 'Protocolo Test',
        ]);
    }

    private function createVet(): Vet
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Argentina Vet',
            'iso_code' => strtoupper(Str::random(2)),
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(),
            'country_id' => $country->id,
            'name' => 'CUIT',
            'validation_regex' => '.*',
        ]);

        return Vet::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Vet Test',
            'slug' => 'vet-test-' . Str::random(6),
            'country_id' => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id' => '20-12345678-9',
            'validated_at' => now(),
        ]);
    }

    private function createClient(): Client
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Argentina Cliente',
            'iso_code' => strtoupper(Str::random(2)),
            'phone_prefix' => '+54',
        ]);

        $documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(),
            'country_id' => $country->id,
            'name' => 'CUIT',
            'validation_regex' => '.*',
        ]);

        return Client::create([
            'guid' => Str::uuid()->toString(),
            'name' => 'Cliente Test',
            'country_id' => $country->id,
            'document_type_id' => $documentType->id,
            'tax_id' => '20-' . Str::random(8) . '-9',
        ]);
    }

    private function createUserForVet(): User
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'vet')->first();

        UserProfile::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $this->vet->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function createManagerProfileForVet(): UserProfile
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'vet')->first();

        return UserProfile::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id' => $this->vet->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_creating_a_program_generates_whatsapp_and_push_recipients_for_a_manager_with_an_active_subscription(): void
    {
        $user = $this->createUserForVet();
        $manager = $this->createManagerProfileForVet();

        PushSubscription::create([
            'user_id' => $manager->user_id,
            'endpoint' => 'https://fcm.test/smoke',
            'endpoint_hash' => hash('sha256', 'https://fcm.test/smoke'),
            'p256dh' => 'key',
            'auth_key' => 'auth',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/vets/{$this->vet->guid}/programs", [
                'client_id' => $this->client->guid,
                'establishment_id' => $this->establishment->guid,
                'protocol_id' => $this->protocol->guid,
                'comments' => null,
                'targets' => [
                    ['target_date' => '2026-08-01', 'animals' => []],
                ],
                'manager_profile_ids' => [$manager->guid],
            ]);

        $response->assertStatus(201);

        $alert = Alert::where('type', AlertType::ProgramCreated)->firstOrFail();

        $this->assertSame(
            1,
            AlertRecipient::where('alert_id', $alert->id)
                ->where('user_profile_id', $manager->id)
                ->where('channel', Channel::Whatsapp)
                ->count(),
        );
        $this->assertSame(
            1,
            AlertRecipient::where('alert_id', $alert->id)
                ->where('user_profile_id', $manager->id)
                ->where('channel', Channel::Push)
                ->count(),
        );
    }
}
