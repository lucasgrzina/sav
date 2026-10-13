<?php

namespace Tests\Concerns;

use App\Enums\ContactType;
use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\ProgramTarget;
use App\Models\Protocol;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Support\Str;

/**
 * A vet (Buenos Aires timezone) with a client, establishment and a protocol that has ONE task
 * (day 0, 08:00) with ONE alert (same day, 08:30 local = 11:30 UTC) for client-owner and
 * client-manager. Helpers create responsibles with optional WhatsApp/e-mail contacts.
 */
trait BuildsProgramAlertFixture
{
    protected Vet $vet;
    protected Client $client;
    protected Establishment $establishment;
    protected Technique $technique;
    protected Protocol $protocol;
    protected ProtocolTask $task;
    protected ProtocolTaskAlert $protocolAlert;
    protected Role $ownerRole;
    protected Role $managerRole;
    protected User $vetUser;

    protected function buildProgramFixture(): void
    {
        foreach (['programs.read', 'programs.create', 'programs.update'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['guid' => Str::uuid()->toString()]);
        }

        $vetRole = $this->role('vet');
        $this->role('vet-assistant'); // no permissions: used for the 403 cases
        $this->ownerRole = $this->role('client-owner');
        $this->managerRole = $this->role('client-manager');
        $vetRole->givePermissionTo(Permission::whereIn('name', ['programs.read', 'programs.create', 'programs.update'])->get());

        $this->vet = $this->makeVet('Vet Test');
        $this->client = $this->makeClient('Cliente Test');
        $this->vet->clients()->attach($this->client->id, ['created_at' => now(), 'updated_at' => now()]);
        $this->establishment = Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $this->client->id, 'name' => 'Estancia Test']);
        $this->technique = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IATF', 'type' => 'technique']);
        $this->protocol = Protocol::create([
            'guid' => Str::uuid()->toString(), 'technique_id' => $this->technique->id, 'vet_id' => $this->vet->id,
            'created_by_type' => 'vet', 'created_by_id' => 1, 'name' => 'Protocolo Test',
        ]);
        $this->task = $this->makeTask('Colecta de embriones', 1);
        $this->protocolAlert = $this->makeProtocolAlert($this->task, '08:30', ['client-owner', 'client-manager'], 'Traer termo');

        $this->vetUser = $this->makeStaffUser($this->vet, 'vet');
    }

    protected function role(string $name): Role
    {
        return Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
    }

    protected function makeVet(string $name): Vet
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina ' . $name, 'iso_code' => strtoupper(Str::random(2)),
            'phone_prefix' => '+54', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
        $docType = DocumentType::create(['guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*']);

        return Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(6),
            'country_id' => $country->id, 'document_type_id' => $docType->id, 'tax_id' => '20-12345678-9', 'validated_at' => now(),
        ]);
    }

    protected function makeClient(string $name): Client
    {
        $country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina ' . $name, 'iso_code' => strtoupper(Str::random(2)), 'phone_prefix' => '+54',
        ]);
        $docType = DocumentType::create(['guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*']);

        return Client::create([
            'guid' => Str::uuid()->toString(), 'name' => $name, 'country_id' => $country->id,
            'document_type_id' => $docType->id, 'tax_id' => '20-' . Str::random(8) . '-9',
        ]);
    }

    protected function makeStaffUser(Vet $vet, string $roleName): User
    {
        $user = User::factory()->create();

        UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => $user->id, 'authenticatable_type' => 'vet',
            'authenticatable_id' => $vet->id, 'role_id' => $this->role($roleName)->id,
        ]);

        return $user;
    }

    protected function makeTask(string $description, int $sortOrder): ProtocolTask
    {
        return ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $this->protocol->id, 'description' => $description,
            'days_offset' => 0, 'time_of_day' => 'after', 'time' => '08:00', 'important' => false, 'sort_order' => $sortOrder,
        ]);
    }

    /** @param list<string> $roles */
    protected function makeProtocolAlert(ProtocolTask $task, string $time, array $roles, ?string $message): ProtocolTaskAlert
    {
        return ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id, 'offset_days' => 0, 'time_of_day' => 'after',
            'time' => $time, 'roles' => $roles, 'message' => $message, 'require_confirmation' => false,
            'sort_order' => (int) ProtocolTaskAlert::where('protocol_task_id', $task->id)->count() + 1,
        ]);
    }

    /**
     * A client-side responsible linked to the establishment. `$whatsapp = null` means no WhatsApp contact.
     */
    protected function makeResponsible(
        ?Role $role = null,
        ?string $whatsapp = '5491122334455',
        ?string $email = null,
        ?string $name = null,
        ?Client $client = null,
    ): UserProfile {
        $client ??= $this->client;
        $user = User::factory()->create($name !== null ? ['name' => $name] : []);

        $profile = UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => $user->id, 'authenticatable_type' => 'client',
            'authenticatable_id' => $client->id, 'role_id' => ($role ?? $this->ownerRole)->id,
        ]);

        if ($client->id === $this->client->id) {
            $this->establishment->staff()->attach($profile->id);
        }

        if ($whatsapp !== null) {
            $profile->contacts()->create(['type' => ContactType::Whatsapp, 'value' => $whatsapp, 'use_for_alerts' => true, 'is_primary' => true]);
        }

        if ($email !== null) {
            $profile->contacts()->create(['type' => ContactType::Email, 'value' => $email, 'use_for_alerts' => true, 'is_primary' => false]);
        }

        return $profile;
    }

    /**
     * @param list<string> $targetDates Y-m-d
     * @param list<UserProfile> $managers
     */
    protected function makeProgram(array $targetDates, array $managers): Program
    {
        $program = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $this->vet->id, 'client_id' => $this->client->id,
            'establishment_id' => $this->establishment->id, 'technique_id' => $this->technique->id, 'protocol_id' => $this->protocol->id,
        ]);

        foreach ($targetDates as $date) {
            ProgramTarget::create(['program_id' => $program->id, 'target_date' => $date]);
        }

        $program->managers()->sync(array_map(fn (UserProfile $p) => $p->id, $managers));

        return $program->fresh();
    }

    /**
     * Body of a preview/store/update request.
     *
     * @param list<array<string, mixed>> $targets [{target_date, guid?, target_ref?}]
     * @param list<UserProfile> $managers
     * @return array<string, mixed>
     */
    protected function draftPayload(array $targets, array $managers, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->guid,
            'establishment_id' => $this->establishment->guid,
            'protocol_id' => $this->protocol->guid,
            'targets' => $targets,
            'manager_profile_ids' => array_map(fn (UserProfile $p) => $p->guid, $managers),
        ], $overrides);
    }
}
