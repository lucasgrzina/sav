<?php

namespace App\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Jobs\SendClientStaffInvitationJob;
use App\Jobs\SendVetStaffInvitationJob;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserProfileService
{
    /** Roles válidos para ser asignados dentro de un tenant. */
    public const TENANT_ROLES = [
        'vet',
        'vet-assistant',
        'vet-administrative',
        'client-owner',
        'client-manager',
        'client-administrative',
    ];

    /** Roles válidos para personal de veterinaria (subset de TENANT_ROLES). */
    public const VET_STAFF_ROLES = ['vet', 'vet-assistant', 'vet-administrative'];

    /** Roles válidos para personal de cliente (subset de TENANT_ROLES). */
    public const CLIENT_STAFF_ROLES = ['client-owner', 'client-manager', 'client-administrative'];

    public function __construct(
        private UserProfileRepositoryInterface $userProfileRepository,
        private UserRepositoryInterface        $userRepository,
        private RoleRepositoryInterface        $roleRepository,
        private ContactService                 $contactService,
    ) {}

    public function list(Vet $vet): Collection
    {
        return $this->userProfileRepository->listForVet($vet);
    }

    public function findByGuid(string $guid): ?UserProfile
    {
        return $this->userProfileRepository->findByGuid($guid);
    }

    /**
     * Busca un UserProfile por guid verificando que pertenece al tenant dado.
     * Retorna null si no existe o si pertenece a otro tenant.
     */
    public function findByGuidForVet(string $guid, Vet $vet): ?UserProfile
    {
        $profile = $this->userProfileRepository->findByGuid($guid);

        if (!$profile) {
            return null;
        }

        if ($profile->authenticatable_type !== 'vet' || $profile->authenticatable_id !== $vet->id) {
            return null;
        }

        return $profile;
    }

    public function resolveUser(string $guid): ?User
    {
        return $this->userRepository->findByGuid($guid);
    }

    public function resolveRole(string $guid): ?Role
    {
        return $this->roleRepository->findByGuid($guid);
    }

    /**
     * Agrega un usuario como miembro de un tenant con un rol específico.
     * Lanza RuntimeException si el usuario ya es miembro del tenant.
     */
    public function addMember(Vet $vet, User $user, Role $role): UserProfile
    {
        $existing = $this->userProfileRepository->findForUserAndVet($user, $vet);

        if ($existing) {
            throw new \RuntimeException('El usuario ya es miembro de esta veterinaria.');
        }

        return $this->userProfileRepository->create([
            'user_id'              => $user->id,
            'authenticatable_type' => 'vet',
            'authenticatable_id'   => $vet->id,
            'role_id'              => $role->id,
        ]);
    }

    public function removeMember(UserProfile $profile): void
    {
        $this->userProfileRepository->destroy($profile);
    }

    public function changeRole(UserProfile $profile, Role $role): UserProfile
    {
        return $this->userProfileRepository->update($profile, ['role_id' => $role->id]);
    }

    public function toggleBlock(UserProfile $profile): UserProfile
    {
        return $this->userProfileRepository->toggleBlock($profile);
    }

    /**
     * Lista los owners (UserProfiles con role client-owner) de un Client dado.
     */
    public function listOwnersForClient(Client $client): Collection
    {
        return $this->userProfileRepository->listOwnersForClient($client);
    }

    /**
     * Crea un client-owner para el Client.
     *
     * Flujo:
     *   1. Buscar User por email.
     *   2a. Si no existe: crear User con password temporal + encolar job de invitación.
     *   2b. Si existe: usar ese User (sin modificarlo, sin reenviar invitación).
     *   3. Verificar que el User no sea ya owner de este Client.
     *   4. Resolver el rol 'client-owner' de la tabla roles.
     *   5. Crear UserProfile(authenticatable_type='client', authenticatable_id=$client->id).
     *   6. Todo dentro de una transacción DB.
     *
     * Lanza RuntimeException si el User ya es owner del Client.
     *
     * @param  Client $client   Client al que se agrega el owner
     * @param  array  $data     Datos validados: { email, first_name, last_name }
     */
    public function addOwnerToClient(Client $client, array $data): UserProfile
    {
        return DB::transaction(function () use ($client, $data) {
            $user      = $this->userRepository->findByEmail($data['email']);
            $isNewUser = false;

            if (!$user) {
                // Usuario nuevo: crear con password temporal y sin verificar
                $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);

                $user = $this->userRepository->create([
                    'first_name'                   => $data['first_name'],
                    'last_name'                    => $data['last_name'],
                    'name'                         => $data['first_name'] . ' ' . $data['last_name'],
                    'email'                        => $data['email'],
                    'password'                     => Hash::make(Str::random(32)),
                    'email_verified_at'            => null,
                    'verification_link_token'      => Str::random(64),
                    'verification_link_expires_at' => now()->addHours($expirationHours),
                ]);

                $isNewUser = true;
            }

            // Verificar duplicado: este User ya es owner de este Client
            $existing = $this->userProfileRepository->findForUserAndClient($user, $client);
            if ($existing) {
                throw new \RuntimeException('Este usuario ya es owner de este cliente.');
            }

            // Resolver rol 'client-owner' desde la tabla roles
            $role = $this->roleRepository->findByName('client-owner');
            if (!$role) {
                throw new \RuntimeException('El rol client-owner no existe en el sistema.');
            }

            // Crear el UserProfile
            $profile = $this->userProfileRepository->create([
                'user_id'              => $user->id,
                'authenticatable_type' => 'client',
                'authenticatable_id'   => $client->id,
                'role_id'              => $role->id,
            ]);

            // Encolar job de invitación solo si el usuario es nuevo
            if ($isNewUser) {
                SendClientOwnerInvitationJob::dispatch($user->id, $client->name);
            }

            return $profile;
        });
    }

    /**
     * Actualiza el rol y los contactos de un miembro de staff en una sola transacción.
     *
     * @param UserProfile $profile    El perfil a actualizar
     * @param string      $roleGuid   GUID del rol a asignar
     * @param array       $contacts   Array de ContactFormItem (con guid? opcional)
     */
    public function updateMember(UserProfile $profile, string $roleGuid, array $contacts): UserProfile
    {
        return DB::transaction(function () use ($profile, $roleGuid, $contacts) {
            $role = $this->roleRepository->findByGuid($roleGuid);
            if (!$role) {
                throw new \RuntimeException('El rol especificado no existe.');
            }

            $profile = $this->changeRole($profile, $role);

            $this->contactService->syncContacts($profile, $contacts);

            return $profile->load(['user', 'role', 'contacts']);
        });
    }

    /**
     * Busca un User por email y verifica si ya es miembro de la vet dada.
     *
     * Retorna:
     *   ['found' => false]                                               — email no existe en el sistema
     *   ['found' => true, 'already_linked' => false, 'user' => [...]]   — existe, no está en esta vet
     *   ['found' => true, 'already_linked' => true,  'user' => [...]]   — existe y ya está en esta vet
     */
    public function lookupForVet(string $email, Vet $vet): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            return ['found' => false, 'already_linked' => null, 'user' => null];
        }

        $existing = $this->userProfileRepository->findForUserAndVet($user, $vet);

        return [
            'found'          => true,
            'already_linked' => $existing !== null,
            'user'           => [
                'guid'       => $user->guid,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
            ],
        ];
    }

    /**
     * Crea un User nuevo (con invitación) y lo asigna como personal de la Vet.
     *
     * Flujo (dentro de DB::transaction):
     *   1. Crear User con password temporal + token de verificación.
     *   2. Resolver el rol por guid (validado en CreateVetStaffRequest).
     *   3. Crear UserProfile(authenticatable_type='vet', authenticatable_id=$vet->id).
     *   4. Crear contactos opcionales usando ContactService (maneja is_primary).
     *   5. Encolar SendVetStaffInvitationJob.
     *
     * @param Vet   $vet   Tenant al que se agrega el personal
     * @param array $data  Datos validados de CreateVetStaffRequest
     */
    public function createAndAssignVetStaff(Vet $vet, array $data): UserProfile
    {
        return DB::transaction(function () use ($vet, $data) {
            $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);

            $user = $this->userRepository->create([
                'first_name'                   => $data['first_name'],
                'last_name'                    => $data['last_name'],
                'name'                         => $data['first_name'] . ' ' . $data['last_name'],
                'email'                        => $data['email'],
                'password'                     => Hash::make(Str::random(32)),
                'email_verified_at'            => null,
                'verification_link_token'      => Str::random(64),
                'verification_link_expires_at' => now()->addHours($expirationHours),
            ]);

            $role = $this->roleRepository->findByGuid($data['role_guid']);
            if (!$role) {
                throw new \RuntimeException('El rol especificado no existe.');
            }

            $profile = $this->userProfileRepository->create([
                'user_id'              => $user->id,
                'authenticatable_type' => 'vet',
                'authenticatable_id'   => $vet->id,
                'role_id'              => $role->id,
            ]);

            foreach ($data['contacts'] ?? [] as $contactData) {
                $this->contactService->create($profile, $contactData);
            }

            SendVetStaffInvitationJob::dispatch($user->id, $vet->name);

            return $profile;
        });
    }

    /**
     * Lista todos los UserProfiles de un Client (todos los roles client-*).
     */
    public function listForClient(Client $client): Collection
    {
        return $this->userProfileRepository->listForClient($client);
    }

    /**
     * Lista solo los UserProfiles de staff de un Client (roles definidos en CLIENT_STAFF_ROLES).
     */
    public function listStaffForClient(Client $client): Collection
    {
        return $this->listForClient($client)
            ->filter(fn ($profile) => in_array($profile->role->name, self::CLIENT_STAFF_ROLES))
            ->values();
    }

    /**
     * Busca un UserProfile por guid verificando que pertenece al client dado.
     * Retorna null si no existe o si pertenece a otro authenticatable.
     */
    public function findByGuidForClient(string $guid, Client $client): ?UserProfile
    {
        $profile = $this->userProfileRepository->findByGuid($guid);

        if (!$profile) {
            return null;
        }

        if ($profile->authenticatable_type !== 'client' || $profile->authenticatable_id !== $client->id) {
            return null;
        }

        return $profile;
    }

    /**
     * Busca un User por email y verifica si ya es miembro del client dado.
     *
     * Retorna:
     *   ['found' => false, 'already_linked' => null, 'user' => null]
     *   ['found' => true, 'already_linked' => false, 'user' => [...]]
     *   ['found' => true, 'already_linked' => true,  'user' => [...]]
     */
    public function lookupForClient(string $email, Client $client): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            return ['found' => false, 'already_linked' => null, 'user' => null];
        }

        $existing = $this->userProfileRepository->findForUserAndClient($user, $client);

        return [
            'found'          => true,
            'already_linked' => $existing !== null,
            'user'           => [
                'guid'       => $user->guid,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
            ],
        ];
    }

    /**
     * Crea un User nuevo (con invitación) y lo asigna como staff del Client.
     *
     * Flujo (dentro de DB::transaction):
     *   1. Crear User con password temporal + token de verificación.
     *   2. Resolver el rol por guid (validado en CreateClientStaffRequest).
     *   3. Crear UserProfile(authenticatable_type='client', authenticatable_id=$client->id).
     *   4. Crear contactos opcionales.
     *   5. Encolar SendClientStaffInvitationJob.
     *
     * @param Client $client  Client al que se agrega el personal
     * @param array  $data    Datos validados de CreateClientStaffRequest
     */
    public function createAndAssignClientStaff(Client $client, array $data): UserProfile
    {
        return DB::transaction(function () use ($client, $data) {
            $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);

            $user = $this->userRepository->create([
                'first_name'                   => $data['first_name'],
                'last_name'                    => $data['last_name'],
                'name'                         => $data['first_name'] . ' ' . $data['last_name'],
                'email'                        => $data['email'],
                'password'                     => Hash::make(Str::random(32)),
                'email_verified_at'            => null,
                'verification_link_token'      => Str::random(64),
                'verification_link_expires_at' => now()->addHours($expirationHours),
            ]);

            $role = $this->roleRepository->findByGuid($data['role_guid']);
            if (!$role) {
                throw new \RuntimeException('El rol especificado no existe.');
            }

            $profile = $this->userProfileRepository->create([
                'user_id'              => $user->id,
                'authenticatable_type' => 'client',
                'authenticatable_id'   => $client->id,
                'role_id'              => $role->id,
            ]);

            foreach ($data['contacts'] ?? [] as $contactData) {
                $this->contactService->create($profile, $contactData);
            }

            SendClientStaffInvitationJob::dispatch($user->id, $client->name);

            return $profile;
        });
    }

    /**
     * Agrega un usuario ya existente como miembro de un Client con un rol específico.
     * Lanza RuntimeException si el usuario ya es miembro del Client.
     */
    public function addMemberToClient(Client $client, User $user, Role $role): UserProfile
    {
        $existing = $this->userProfileRepository->findForUserAndClient($user, $client);

        if ($existing) {
            throw new \RuntimeException('El usuario ya es miembro de este cliente.');
        }

        return $this->userProfileRepository->create([
            'user_id'              => $user->id,
            'authenticatable_type' => 'client',
            'authenticatable_id'   => $client->id,
            'role_id'              => $role->id,
        ]);
    }
}
