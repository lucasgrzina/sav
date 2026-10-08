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
 * A diferencia de un seeder con datos genéricos ("Empresa Test 1",
 * "Cliente 1-2"), este arma un dataset verosímil del dominio ganadero
 * argentino: consultoras empresas con matrícula y CUIT válido, clientes
 * agropecuarios con domicilio real de partido/departamento, y establecimientos
 * con RENSPA y coordenadas geográficas coherentes con su localidad.
 *
 * Estructura generada:
 * - 3 vets, cada una con 3 usuarios (vet, vet-administrative, vet-assistant).
 * - 2 clientes por vet, cada uno con 3 usuarios (client-owner, client-manager,
 *   client-administrative) y 2 establecimientos.
 *
 * Simplificaciones deliberadas para este seeder de prueba (no son reglas de
 * producción):
 * - Cada usuario pertenece a UN solo tenant (una vet o un cliente), nunca a
 *   ambos ni a varios.
 * - Cada cliente se asocia a UNA sola vet vía la tabla pivot `client_vet`,
 *   aunque el modelo soporta muchos a muchos.
 *
 * Contactos: vets, clientes y perfiles de usuario reciben SIEMPRE un contacto
 * de email y uno de WhatsApp, ambos con `use_for_alerts = true`, para que el
 * pipeline de notificaciones (DeliverAlertJob, GatewayRegistry, cadena de
 * fallback) sea ejecutable inmediatamente después de `migrate --seed`.
 *
 * El número de WhatsApp es el MISMO para todos los contactos: el que está
 * dado de alta en el sandbox de Twilio, de lo contrario los envíos fallan.
 * Se puede sobreescribir con `SEED_ALERT_WHATSAPP` en el `.env`.
 *
 * Password de todos los usuarios generados: "password" (default de
 * UserFactory, ver back/database/factories/UserFactory.php).
 */
class TestDataSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Número de WhatsApp registrado en el sandbox de Twilio. Todos los
     * contactos de alerta lo comparten para que los envíos lleguen a un
     * destino habilitado. Override: SEED_ALERT_WHATSAPP.
     */
    private const DEFAULT_ALERT_WHATSAPP = '5491134290838';

    /**
     * Dataset de empresas, sus clientes, establecimientos y staff.
     *
     * `tax_id_base` son los 10 primeros dígitos del CUIT; el dígito
     * verificador se calcula en {@see cuit()}.
     */
    private const VETS = [
        [
            'name'                => 'Empresa SAV',
            'slug'                => 'empresa-sav',
            'tax_id_base'         => '3071094825',
            'registration_number' => 'MP 4821',
            'pdf_title'           => 'Empresa SAV',
            'pdf_subtitle'        => 'Reproducción y sanidad bovina · Cañuelas, Buenos Aires',
            'domain'              => 'imtesa.com.ar',
            'staff'               => [
                'vet'                => ['Martín', 'Mujica', 'mmujica'],
                'vet-administrative' => ['Gustavo', 'Peralta', 'gperalta'],
                'vet-assistant'      => ['Edgardo', 'Velez', 'evelez'],
            ],
            'clients' => [
                [
                    'name'        => 'Agropecuaria ID',
                    'tax_id_base' => '3070583914',
                    'address'     => 'Ruta Provincial 6 km 42',
                    'city'        => 'Cañuelas',
                    'state'       => 'Buenos Aires',
                    'zip_code'    => '1814',
                    'domain'      => 'identidad-digital.com.ar',
                    'staff'       => [
                        'client-owner'          => ['Gustavo', 'Marra', 'gmarra'],
                        'client-manager'        => ['Lucas', 'Grzina', 'lgrzina'],
                        'client-administrative' => ['Federico', 'Roiffe', 'froiffe'],
                    ],
                    'establishments' => [
                        [
                            'name'      => 'Estancia La Invernada',
                            'renspa'    => '01.021.0.01847/12',
                            'address'   => 'Ruta Provincial 6 km 45,5',
                            'city'      => 'Cañuelas',
                            'state'     => 'Buenos Aires',
                            'zip_code'  => '1814',
                            'latitude'  => -35.05194400,
                            'longitude' => -58.75972200,
                        ],
                        [
                            'name'      => 'Campo El Retiro',
                            'renspa'    => '01.066.0.00932/03',
                            'address'   => 'Camino Vecinal La Paloma s/n',
                            'city'      => 'San Miguel del Monte',
                            'state'     => 'Buenos Aires',
                            'zip_code'  => '7220',
                            'latitude'  => -35.42750000,
                            'longitude' => -58.80833300,
                        ],
                    ],
                ]
            ],
        ],
        [
            'name'                => 'Grupo Veterinario Pampa Húmeda',
            'slug'                => 'grupo-veterinario-pampa-humeda',
            'tax_id_base'         => '3071236704',
            'registration_number' => 'MP 3157',
            'pdf_title'           => 'Grupo Veterinario Pampa Húmeda',
            'pdf_subtitle'        => 'Servicios reproductivos para rodeos de cría · Venado Tuerto, Santa Fe',
            'domain'              => 'pampahumeda.com.ar',
            'staff'               => [
                'vet'                => ['Sebastián', 'Molina', 'smolina'],
                'vet-administrative' => ['Lucía', 'Bertone', 'lbertone'],
                'vet-assistant'      => ['Nicolás', 'Sosa', 'nsosa'],
            ],
            'clients' => [
                [
                    'name'        => 'Ganadera Los Algarrobos S.A.',
                    'tax_id_base' => '3070914623',
                    'address'     => 'Bv. Almirante Brown 785',
                    'city'        => 'Venado Tuerto',
                    'state'       => 'Santa Fe',
                    'zip_code'    => '2600',
                    'domain'      => 'losalgarrobos.com.ar',
                    'staff'       => [
                        'client-owner'          => ['Jorge', 'Bianchi', 'jbianchi'],
                        'client-manager'        => ['Silvina', 'Colombo', 'scolombo'],
                        'client-administrative' => ['Emiliano', 'Rossi', 'erossi'],
                    ],
                    'establishments' => [
                        [
                            'name'      => 'Estancia Los Algarrobos',
                            'renspa'    => '21.063.0.01126/09',
                            'address'   => 'Ruta Nacional 8 km 363',
                            'city'      => 'Venado Tuerto',
                            'state'     => 'Santa Fe',
                            'zip_code'  => '2600',
                            'latitude'  => -33.74583300,
                            'longitude' => -61.96888900,
                        ],
                        [
                            'name'      => 'Campo San Cayetano',
                            'renspa'    => '21.063.0.00874/04',
                            'address'   => 'Ruta Provincial 94 km 27',
                            'city'      => 'Teodelina',
                            'state'     => 'Santa Fe',
                            'zip_code'  => '6009',
                            'latitude'  => -34.19277800,
                            'longitude' => -61.51361100,
                        ],
                    ],
                ],
                [
                    'name'        => 'Agrícola Ganadera El Ombú S.R.L.',
                    'tax_id_base' => '3070462381',
                    'address'     => 'San Martín 512',
                    'city'        => 'Firmat',
                    'state'       => 'Santa Fe',
                    'zip_code'    => '2630',
                    'domain'      => 'elombu.com.ar',
                    'staff'       => [
                        'client-owner'          => ['Marta', 'Zanetti', 'mzanetti'],
                        'client-manager'        => ['Pablo', 'Ferreyra', 'pferreyra'],
                        'client-administrative' => ['Rocío', 'Ledesma', 'rledesma'],
                    ],
                    'establishments' => [
                        [
                            'name'      => 'Estancia El Ombú',
                            'renspa'    => '21.028.0.01593/06',
                            'address'   => 'Ruta Provincial 93 km 12',
                            'city'      => 'Firmat',
                            'state'     => 'Santa Fe',
                            'zip_code'  => '2630',
                            'latitude'  => -33.45861100,
                            'longitude' => -61.48333300,
                        ],
                        [
                            'name'      => 'Campo La Chacra',
                            'renspa'    => '21.028.0.00347/01',
                            'address'   => 'Camino Rural 15 s/n',
                            'city'      => 'Chovet',
                            'state'     => 'Santa Fe',
                            'zip_code'  => '2607',
                            'latitude'  => -33.56666700,
                            'longitude' => -61.65000000,
                        ],
                    ],
                ],
            ],
        ],
        [
            'name'                => 'Servicios Ganaderos Río Cuarto',
            'slug'                => 'servicios-ganaderos-rio-cuarto',
            'tax_id_base'         => '3070738159',
            'registration_number' => 'MP 2064',
            'pdf_title'           => 'Servicios Ganaderos Río Cuarto',
            'pdf_subtitle'        => 'Transferencia embrionaria y sanidad de rodeo · Río Cuarto, Córdoba',
            'domain'              => 'sgriocuarto.com.ar',
            'staff'               => [
                'vet'                => ['Federico', 'Aguirre', 'faguirre'],
                'vet-administrative' => ['Paula', 'Cardozo', 'pcardozo'],
                'vet-assistant'      => ['Matías', 'Robledo', 'mrobledo'],
            ],
            'clients' => [
                [
                    'name'        => 'Cabaña Santa Rita S.A.',
                    'tax_id_base' => '3070185342',
                    'address'     => 'Ruta Nacional 36 km 601',
                    'city'        => 'Río Cuarto',
                    'state'       => 'Córdoba',
                    'zip_code'    => '5800',
                    'domain'      => 'cabanasantarita.com.ar',
                    'staff'       => [
                        'client-owner'          => ['Alejandro', 'Funes', 'afunes'],
                        'client-manager'        => ['Natalia', 'Bustos', 'nbustos'],
                        'client-administrative' => ['Leandro', 'Vega', 'lvega'],
                    ],
                    'establishments' => [
                        [
                            'name'      => 'Cabaña Santa Rita',
                            'renspa'    => '07.049.0.02784/11',
                            'address'   => 'Ruta Nacional 36 km 603',
                            'city'      => 'Río Cuarto',
                            'state'     => 'Córdoba',
                            'zip_code'  => '5800',
                            'latitude'  => -33.12305600,
                            'longitude' => -64.34944400,
                        ],
                        [
                            'name'      => 'Campo Las Higueras',
                            'renspa'    => '07.049.0.01206/05',
                            'address'   => 'Camino a Las Higueras km 6',
                            'city'      => 'Las Higueras',
                            'state'     => 'Córdoba',
                            'zip_code'  => '5805',
                            'latitude'  => -33.07333300,
                            'longitude' => -64.27000000,
                        ],
                    ],
                ],
                [
                    'name'        => 'Agropecuaria Los Cardales S.R.L.',
                    'tax_id_base' => '3069825407',
                    'address'     => 'Bv. Vélez Sarsfield 934',
                    'city'        => 'Villa María',
                    'state'       => 'Córdoba',
                    'zip_code'    => '5900',
                    'domain'      => 'loscardales.com.ar',
                    'staff'       => [
                        'client-owner'          => ['Osvaldo', 'Miranda', 'omiranda'],
                        'client-manager'        => ['Andrea', 'Correa', 'acorrea'],
                        'client-administrative' => ['Ignacio', 'Bravo', 'ibravo'],
                    ],
                    'establishments' => [
                        [
                            'name'      => 'Estancia Los Cardales',
                            'renspa'    => '07.063.0.01958/08',
                            'address'   => 'Ruta Provincial 158 km 174',
                            'city'      => 'Villa María',
                            'state'     => 'Córdoba',
                            'zip_code'  => '5900',
                            'latitude'  => -32.40750000,
                            'longitude' => -63.24027800,
                        ],
                        [
                            'name'      => 'Campo El Chañar',
                            'renspa'    => '07.063.0.00681/02',
                            'address'   => 'Ruta Provincial 2 km 31',
                            'city'      => 'Tío Pujio',
                            'state'     => 'Córdoba',
                            'zip_code'  => '5936',
                            'latitude'  => -32.30861100,
                            'longitude' => -63.35111100,
                        ],
                    ],
                ],
            ],
        ],
    ];

    /** Credenciales generadas, para mostrarlas al final del seeding. */
    private array $credentials = [];

    public function run(): void
    {
        $country      = Country::where('iso_code', 'AR')->firstOrFail();
        $documentType = DocumentType::where('country_id', $country->id)
            ->where('name', 'CUIT')
            ->firstOrFail();

        foreach (self::VETS as $vetData) {
            $vet = Vet::create([
                'guid'                => Str::uuid()->toString(),
                'name'                => $vetData['name'],
                'slug'                => $vetData['slug'],
                'country_id'          => $country->id,
                'document_type_id'    => $documentType->id,
                'tax_id'              => $this->cuit($vetData['tax_id_base']),
                'registration_number' => $vetData['registration_number'],
                'pdf_title'           => $vetData['pdf_title'],
                'pdf_subtitle'        => $vetData['pdf_subtitle'],
                'validated_at'        => now(),
            ]);

            $this->createAlertContacts($vet, "contacto@{$vetData['domain']}", 'Casa central');

            foreach ($vetData['staff'] as $roleName => [$firstName, $lastName, $mailbox]) {
                $this->createTenantUser(
                    $vet,
                    'vet',
                    $roleName,
                    $firstName,
                    $lastName,
                    "{$mailbox}@{$vetData['domain']}",
                    $vetData['name'],
                );
            }

            foreach ($vetData['clients'] as $clientData) {
                $client = Client::create([
                    'guid'             => Str::uuid()->toString(),
                    'name'             => $clientData['name'],
                    'country_id'       => $country->id,
                    'document_type_id' => $documentType->id,
                    'tax_id'           => $this->cuit($clientData['tax_id_base']),
                    'address'          => $clientData['address'],
                    'city'             => $clientData['city'],
                    'state'            => $clientData['state'],
                    'zip_code'         => $clientData['zip_code'],
                ]);

                $client->vets()->attach($vet->id);

                $this->createAlertContacts($client, "administracion@{$clientData['domain']}", 'Administración');

                $establishments = [];
                foreach ($clientData['establishments'] as $establishmentData) {
                    $establishments[] = Establishment::create([
                        'guid'      => Str::uuid()->toString(),
                        'client_id' => $client->id,
                        ...$establishmentData,
                    ]);
                }

                $clientProfileIds = [];
                foreach ($clientData['staff'] as $roleName => [$firstName, $lastName, $mailbox]) {
                    $clientProfileIds[] = $this->createTenantUser(
                        $client,
                        'client',
                        $roleName,
                        $firstName,
                        $lastName,
                        "{$mailbox}@{$clientData['domain']}",
                        $clientData['name'],
                    )->id;
                }

                // Link all client staff to every establishment of the client (same result as the backfill migration).
                foreach ($establishments as $establishment) {
                    $establishment->staff()->syncWithoutDetaching($clientProfileIds);
                }
            }
        }

        $this->reportCredentials();
    }

    /**
     * Crea el usuario, su perfil sobre el tenant indicado y sus contactos de alerta.
     */
    private function createTenantUser(
        Vet|Client $tenant,
        string $authenticatableType,
        string $roleName,
        string $firstName,
        string $lastName,
        string $email,
        string $tenantName,
    ): UserProfile {
        $user = User::factory()->create([
            'guid'       => Str::uuid()->toString(),
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'name'       => "{$firstName} {$lastName}",
            'email'      => $email,
        ]);

        $profile = UserProfile::create([
            'guid'                 => Str::uuid()->toString(),
            'user_id'              => $user->id,
            'authenticatable_type' => $authenticatableType,
            'authenticatable_id'   => $tenant->id,
            'role_id'              => Role::where('name', $roleName)->firstOrFail()->id,
        ]);

        $this->createAlertContacts($profile, $email, 'Personal');

        $this->credentials[] = [$tenantName, $roleName, $email];

        return $profile;
    }

    /**
     * Crea el par de contactos de alerta (email + WhatsApp) del contactable.
     *
     * `use_for_alerts` es un flag opt-in de negocio en producción (default false,
     * ver la migración de contacts y ContactService). Acá se fuerza a true para
     * que el pipeline de notificaciones sea ejecutable apenas termina el seeding,
     * sin esperar que un usuario real haga el opt-in.
     */
    private function createAlertContacts(Vet|Client|UserProfile $contactable, string $email, string $label): void
    {
        // Alias del morphMap (ver AppServiceProvider), no el FQCN.
        $contactableType = $contactable->getMorphClass();

        Contact::create([
            'guid'             => Str::uuid()->toString(),
            'contactable_type' => $contactableType,
            'contactable_id'   => $contactable->id,
            'type'             => ContactType::Email,
            'label'            => $label,
            'value'            => $email,
            'is_primary'       => true,
            'use_for_alerts'   => true,
        ]);

        Contact::create([
            'guid'             => Str::uuid()->toString(),
            'contactable_type' => $contactableType,
            'contactable_id'   => $contactable->id,
            'type'             => ContactType::Whatsapp,
            'label'            => $label,
            'value'            => $this->alertWhatsapp(),
            'is_primary'       => true,
            'use_for_alerts'   => true,
        ]);
    }

    private function alertWhatsapp(): string
    {
        $phone = (string) env('SEED_ALERT_WHATSAPP', self::DEFAULT_ALERT_WHATSAPP);

        return preg_replace('/\D/', '', $phone) ?: self::DEFAULT_ALERT_WHATSAPP;
    }

    /**
     * Completa un CUIT de 10 dígitos con su dígito verificador (módulo 11).
     */
    private function cuit(string $base): string
    {
        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum     = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $base[$index]) * $weight;
        }

        $checkDigit = 11 - ($sum % 11);
        $checkDigit = match ($checkDigit) {
            11      => 0,
            10      => 9,
            default => $checkDigit,
        };

        return sprintf('%s-%s-%d', substr($base, 0, 2), substr($base, 2, 8), $checkDigit);
    }

    private function reportCredentials(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->newLine();
        $this->command->info('TestDataSeeder: usuarios creados (password: "password")');
        $this->command->table(['Tenant', 'Rol', 'Email'], $this->credentials);
        $this->command->info("WhatsApp de alertas (todos los contactos): {$this->alertWhatsapp()}");
    }
}
