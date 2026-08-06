<?php

namespace Database\Seeders;

use App\Enums\ContactType;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder de datos de prueba para entorno de desarrollo.
 *
 * Genera vets, clientes, establecimientos y un usuario por cada rol tenant
 * disponible (vet, vet-administrative, vet-assistant, client-owner,
 * client-manager, client-administrative) para poder probar el login manual
 * de cada perfil.
 *
 * Simplificaciones deliberadas para este seeder de prueba (no son reglas de
 * producción):
 * - Cada usuario pertenece a UN solo tenant (una vet o un cliente), nunca a
 *   ambos ni a varios.
 * - Cada cliente se asocia a UNA sola vet vía la tabla pivot `client_vet`,
 *   aunque el modelo soporta muchos a muchos.
 *
 * Password de todos los usuarios generados: "password" (default de
 * UserFactory, ver back/database/factories/UserFactory.php).
 */
class TestDataSeeder extends Seeder
{
    use WithoutModelEvents;

    private const VETS_COUNT = 3;

    private const CLIENTS_PER_VET = 2;

    private const ESTABLISHMENTS_PER_CLIENT = 2;

    /** Sequence used to build fake, obviously-not-real phone numbers for seeded contacts. */
    private int $alertContactSequence = 0;

    public function run(): void
    {
        $country      = Country::where('iso_code', 'AR')->firstOrFail();
        $documentType = DocumentType::where('country_id', $country->id)
            ->where('name', 'CUIT')
            ->firstOrFail();

        $vetRoles = [
            'vet'                => Role::where('name', 'vet')->firstOrFail(),
            'vet-administrative' => Role::where('name', 'vet-administrative')->firstOrFail(),
            'vet-assistant'      => Role::where('name', 'vet-assistant')->firstOrFail(),
        ];

        $clientRoles = [
            'client-owner'          => Role::where('name', 'client-owner')->firstOrFail(),
            'client-manager'        => Role::where('name', 'client-manager')->firstOrFail(),
            'client-administrative' => Role::where('name', 'client-administrative')->firstOrFail(),
        ];

        for ($vetIndex = 1; $vetIndex <= self::VETS_COUNT; $vetIndex++) {
            $vet = Vet::create([
                'guid'              => Str::uuid()->toString(),
                'name'              => "Veterinaria Test {$vetIndex}",
                'slug'              => "veterinaria-test-{$vetIndex}",
                'country_id'        => $country->id,
                'document_type_id'  => $documentType->id,
                'tax_id'            => sprintf('30-%08d-%d', $vetIndex, $vetIndex),
                'validated_at'      => now(),
            ]);

            foreach ($vetRoles as $roleKey => $role) {
                $email = "vet{$vetIndex}-{$roleKey}@test.com";
                $user  = $this->createUser($email, "Vet{$vetIndex}", ucfirst($roleKey));

                $profile = UserProfile::create([
                    'guid'                 => Str::uuid()->toString(),
                    'user_id'              => $user->id,
                    'authenticatable_type' => 'vet',
                    'authenticatable_id'   => $vet->id,
                    'role_id'              => $role->id,
                ]);

                $this->createAlertContacts($profile, $email);
            }

            for ($clientIndex = 1; $clientIndex <= self::CLIENTS_PER_VET; $clientIndex++) {
                $client = Client::create([
                    'guid'             => Str::uuid()->toString(),
                    'name'             => "Cliente {$vetIndex}-{$clientIndex}",
                    'country_id'       => $country->id,
                    'document_type_id' => $documentType->id,
                    'tax_id'           => sprintf('20-%08d-%d', ($vetIndex * 10) + $clientIndex, $clientIndex),
                    'address'          => "Calle Falsa {$clientIndex}23",
                    'city'             => 'Buenos Aires',
                    'state'            => 'Buenos Aires',
                    'zip_code'         => '1000',
                ]);

                $client->vets()->attach($vet->id);

                for ($establishmentIndex = 1; $establishmentIndex <= self::ESTABLISHMENTS_PER_CLIENT; $establishmentIndex++) {
                    Establishment::create([
                        'guid'      => Str::uuid()->toString(),
                        'client_id' => $client->id,
                        'name'      => "Establecimiento {$vetIndex}-{$clientIndex}-{$establishmentIndex}",
                        'city'      => 'Buenos Aires',
                        'zip_code'  => '1000',
                    ]);
                }

                foreach ($clientRoles as $roleKey => $role) {
                    $email = "vet{$vetIndex}-client{$clientIndex}-{$roleKey}@test.com";
                    $user  = $this->createUser($email, "Cliente{$vetIndex}{$clientIndex}", ucfirst($roleKey));

                    $profile = UserProfile::create([
                        'guid'                 => Str::uuid()->toString(),
                        'user_id'              => $user->id,
                        'authenticatable_type' => 'client',
                        'authenticatable_id'   => $client->id,
                        'role_id'              => $role->id,
                    ]);

                    $this->createAlertContacts($profile, $email);
                }
            }
        }
    }

    private function createUser(string $email, string $firstName, string $lastName): User
    {
        return User::factory()->create([
            'guid'       => Str::uuid()->toString(),
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'name'       => "{$firstName} {$lastName}",
            'email'      => $email,
        ]);
    }

    /**
     * `use_for_alerts` is an opt-in business flag in production (default false, see the
     * contacts migration and ContactService). It is set here only so the notification
     * pipeline (DeliverAlertJob, GatewayRegistry, fallback chain) is exercisable right
     * after `migrate --seed`, without waiting for a real user to opt in. Values are
     * obviously fake so nobody mistakes them for real contacts.
     */
    private function createAlertContacts(UserProfile $profile, string $email): void
    {
        $this->alertContactSequence++;
        $fakePhone = sprintf('549110000%04d', $this->alertContactSequence);

        Contact::create([
            'guid'                 => Str::uuid()->toString(),
            'contactable_type'     => 'user_profile',
            'contactable_id'       => $profile->id,
            'type'                 => ContactType::Whatsapp,
            'value'                => $fakePhone,
            'is_primary'           => true,
            'use_for_alerts'       => true,
        ]);

        Contact::create([
            'guid'                 => Str::uuid()->toString(),
            'contactable_type'     => 'user_profile',
            'contactable_id'       => $profile->id,
            'type'                 => ContactType::Email,
            'value'                => $email,
            'is_primary'           => true,
            'use_for_alerts'       => true,
        ]);
    }
}
