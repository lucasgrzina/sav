<?php

namespace Tests\Feature;

use App\Events\EstablishmentStaffUnlinkedEvent;
use App\Events\ProgramTargetsChangedEvent;
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
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstablishmentStaffControllerTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;
    private DocumentType $documentType;
    private Vet $vet;
    private Client $client;
    private Establishment $establishment;
    private User $actor;
    private User $admin;
    private Role $vetRole;
    private Role $ownerRole;
    private Role $managerRole;
    private Role $vetManagerRole;
    private Role $assistantRole;
    private Technique $technique;
    private Protocol $protocol;
    private bool $taskAlertSeeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['establishments.update', 'establishments.read', 'clients.staff.read'] as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $this->vetRole = $this->makeRole('vet', Role::TYPE_TENANT);
        $this->vetRole->givePermissionTo(['establishments.update', 'establishments.read', 'clients.staff.read']);
        // vet-assistant reads establishments but has neither establishments.update nor clients.staff.read.
        $this->assistantRole = $this->makeRole('vet-assistant', Role::TYPE_TENANT);
        $this->assistantRole->givePermissionTo(['establishments.read']);
        $this->vetManagerRole = $this->makeRole('vet-manager', Role::TYPE_TENANT);
        $this->ownerRole      = $this->makeRole('client-owner', Role::TYPE_TENANT);
        $this->managerRole    = $this->makeRole('client-manager', Role::TYPE_TENANT);
        $adminRole            = $this->makeRole('platform-admin', Role::TYPE_PLATFORM);
        $adminRole->givePermissionTo(['establishments.update', 'establishments.read', 'clients.staff.read']);
        $this->makeRole('platform-viewer', Role::TYPE_PLATFORM)->givePermissionTo('establishments.read');

        $this->country = Country::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54',
        ]);
        $this->documentType = DocumentType::create([
            'guid' => Str::uuid()->toString(), 'country_id' => $this->country->id, 'name' => 'CUIT', 'validation_regex' => '.*',
        ]);

        $this->vet    = $this->makeVet();
        $this->client = $this->makeClient($this->vet);
        $this->establishment = $this->makeEstablishment($this->client);

        $this->actor = User::factory()->create();
        $this->makeProfile($this->actor, 'vet', $this->vet->id, $this->vetRole);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('platform-admin');

        $this->technique = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $this->protocol  = Protocol::create([
            'guid' => Str::uuid()->toString(), 'technique_id' => $this->technique->id, 'vet_id' => $this->vet->id,
            'created_by_type' => 'vet', 'created_by_id' => 1, 'name' => 'Protocolo',
        ]);
    }

    private function makeRole(string $name, string $type): Role
    {
        return Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => $type],
        );
    }

    private function makeVet(): Vet
    {
        return Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet ' . Str::random(4),
            'slug' => 'vet-' . Str::random(8), 'country_id' => $this->country->id,
            'document_type_id' => $this->documentType->id, 'tax_id' => '20-' . random_int(10000000, 99999999) . '-9',
            'validated_at' => now(),
        ]);
    }

    private function makeClient(?Vet $vet = null): Client
    {
        $client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente ' . Str::random(4),
            'country_id' => $this->country->id, 'document_type_id' => $this->documentType->id,
            'tax_id' => '20-' . random_int(10000000, 99999999) . '-1',
        ]);
        if ($vet) {
            $client->vets()->attach($vet);
        }

        return $client;
    }

    private function makeEstablishment(Client $client, string $name = 'Estancia'): Establishment
    {
        return Establishment::create([
            'guid' => Str::uuid()->toString(), 'client_id' => $client->id, 'name' => $name,
        ]);
    }

    private function makeProfile(User $user, string $type, int $ownerId, Role $role): UserProfile
    {
        return UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => $user->id,
            'authenticatable_type' => $type, 'authenticatable_id' => $ownerId, 'role_id' => $role->id,
        ]);
    }

    private function clientProfile(?Client $client = null, ?Role $role = null): UserProfile
    {
        return $this->makeProfile(User::factory()->create(), 'client', ($client ?? $this->client)->id, $role ?? $this->ownerRole);
    }

    private function tenantUrl(?Establishment $est = null, ?Client $client = null, ?Vet $vet = null): string
    {
        $est ??= $this->establishment;

        return "/api/v1/vets/" . ($vet ?? $this->vet)->guid . "/clients/" . ($client ?? $this->client)->guid
            . "/establishments/{$est->guid}/staff";
    }

    private function adminUrl(?Establishment $est = null, ?Client $client = null): string
    {
        return "/api/v1/admin/clients/" . ($client ?? $this->client)->guid
            . "/establishments/" . ($est ?? $this->establishment)->guid . "/staff";
    }

    private function tenantPut(array $guids, ?string $url = null)
    {
        return $this->actingAs($this->actor, 'sanctum')
            ->putJson($url ?? $this->tenantUrl(), ['user_profile_guids' => $guids]);
    }

    private function actorWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $this->makeProfile($user, 'vet', $this->vet->id, $role);

        return $user;
    }

    private function establishmentsIndexUrl(): string
    {
        return "/api/v1/vets/{$this->vet->guid}/clients/{$this->client->guid}/establishments";
    }

    public function test_sync_links_two_profiles_and_returns_staff(): void
    {
        $a = $this->clientProfile();
        $b = $this->clientProfile(null, $this->managerRole);

        $this->tenantPut([$a->guid, $b->guid])
            ->assertOk()
            ->assertJsonCount(2, 'data.staff')
            ->assertJsonPath('data.staff.0.role.name', fn ($n) => in_array($n, ['client-owner', 'client-manager'], true));

        $this->assertDatabaseCount('establishment_user_profile', 2);
    }

    public function test_sync_replaces_final_state_and_empty_array_unlinks_all(): void
    {
        $a = $this->clientProfile();
        $b = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id, $b->id]);

        $this->tenantPut([$b->guid])->assertOk()->assertJsonCount(1, 'data.staff');
        $this->assertSame([$b->id], $this->establishment->staff()->pluck('user_profiles.id')->all());

        $this->tenantPut([])->assertOk()->assertJsonCount(0, 'data.staff');
        $this->assertDatabaseCount('establishment_user_profile', 0);
    }

    public function test_sync_requires_the_field(): void
    {
        $this->actingAs($this->actor, 'sanctum')
            ->putJson($this->tenantUrl(), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_profile_guids']);
    }

    public function test_sync_rejects_duplicated_guids(): void
    {
        $a = $this->clientProfile();

        $this->tenantPut([$a->guid, $a->guid])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_profile_guids.0']);
    }

    public function test_sync_rejects_profile_of_another_client(): void
    {
        $other = $this->clientProfile($this->makeClient($this->vet));

        $this->tenantPut([$other->guid])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'staff_client_mismatch');

        $this->assertDatabaseCount('establishment_user_profile', 0);
    }

    public function test_sync_rejects_vet_profile_and_unknown_guid(): void
    {
        $vetProfile = $this->makeProfile(User::factory()->create(), 'vet', $this->vet->id, $this->vetRole);

        $this->tenantPut([$vetProfile->guid])->assertStatus(422)->assertJsonPath('errors.reason', 'staff_client_mismatch');
        $this->tenantPut([Str::uuid()->toString()])->assertStatus(422)->assertJsonPath('errors.reason', 'staff_client_mismatch');
    }

    public function test_sync_rejects_client_profile_with_non_staff_role(): void
    {
        $role = $this->makeRole('client-other', Role::TYPE_TENANT);
        $p    = $this->clientProfile(null, $role);

        $this->tenantPut([$p->guid])->assertStatus(422)->assertJsonPath('errors.reason', 'staff_client_mismatch');
    }

    public function test_sync_returns_404_for_establishment_of_another_client(): void
    {
        $otherClient = $this->makeClient($this->vet);
        $otherEst    = $this->makeEstablishment($otherClient);

        $this->tenantPut([], $this->tenantUrl($otherEst))->assertNotFound();
    }

    public function test_sync_returns_404_for_client_of_another_vet(): void
    {
        $foreignClient = $this->makeClient($this->makeVet());
        $foreignEst    = $this->makeEstablishment($foreignClient);

        $this->tenantPut([], $this->tenantUrl($foreignEst, $foreignClient))->assertNotFound();
    }

    public function test_same_profile_can_be_linked_to_two_establishments(): void
    {
        $second = $this->makeEstablishment($this->client, 'Otra');
        $a      = $this->clientProfile();

        $this->tenantPut([$a->guid])->assertOk();
        $this->tenantPut([$a->guid], $this->tenantUrl($second))->assertOk();

        $this->assertSame(2, $a->establishments()->count());
    }

    public function test_admin_sync_links_and_rejects_mismatch(): void
    {
        $a     = $this->clientProfile();
        $other = $this->clientProfile($this->makeClient());

        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->adminUrl(), ['user_profile_guids' => [$a->guid]])
            ->assertOk()
            ->assertJsonCount(1, 'data.staff');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->adminUrl(), ['user_profile_guids' => [$other->guid]])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'staff_client_mismatch');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->adminUrl($this->makeEstablishment($this->makeClient())), ['user_profile_guids' => []])
            ->assertNotFound();
    }

    public function test_index_embeds_staff_and_staff_count(): void
    {
        $a = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id]);
        $this->makeEstablishment($this->client, 'Vacia');

        $data = $this->actingAs($this->actor, 'sanctum')->getJson($this->establishmentsIndexUrl());

        $data->assertOk();
        $counts = collect($data->json('data'))->pluck('staff_count')->sort()->values()->all();
        $this->assertSame([0, 1], $counts);
    }

    public function test_unlinking_detaches_manager_from_active_programs_only(): void
    {
        Event::fake([ProgramTargetsChangedEvent::class]);

        $a = $this->clientProfile();
        $b = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id, $b->id]);

        $otherEst = $this->makeEstablishment($this->client, 'Otra');
        $otherEst->staff()->sync([$a->id]);

        $active    = $this->makeProgram($this->establishment, [$a, $b]);
        $cancelled = $this->makeProgram($this->establishment, [$a], cancelled: true);
        $elsewhere = $this->makeProgram($otherEst, [$a]);

        $this->tenantPut([$b->guid])->assertOk();

        $this->assertSame([$b->id], $active->managers()->pluck('user_profiles.id')->all());
        $this->assertSame([$a->id], $cancelled->managers()->pluck('user_profiles.id')->all());
        $this->assertSame([$a->id], $elsewhere->managers()->pluck('user_profiles.id')->all());

        Event::assertDispatchedTimes(ProgramTargetsChangedEvent::class, 1);
    }

    public function test_unlinking_regenerates_real_alerts_keeping_only_remaining_managers(): void
    {
        $a = $this->clientProfile();
        $b = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id, $b->id]);
        $program = $this->makeProgram($this->establishment, [$a, $b]);
        $this->seedTaskDueAlerts($program);

        $this->assertGreaterThan(0, $this->pendingRecipients($program, $a));
        $this->assertGreaterThan(0, $this->pendingRecipients($program, $b));

        $this->tenantPut([$b->guid])->assertOk();

        $this->assertSame(0, $this->pendingRecipients($program, $a));
        $this->assertGreaterThan(0, $this->pendingRecipients($program, $b));
        $this->assertSame([$b->id], $program->managers()->pluck('user_profiles.id')->all());
    }

    public function test_unlinking_the_last_manager_leaves_program_without_pending_task_due_alerts(): void
    {
        $a = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id]);
        $program = $this->makeProgram($this->establishment, [$a]);
        $this->seedTaskDueAlerts($program);
        $this->assertGreaterThan(0, $this->pendingRecipients($program, $a));

        $this->tenantPut([])->assertOk();

        $this->assertSame(0, $program->managers()->count());
        $this->assertSame(0, Alert::where('type', AlertType::ProgramTaskDue)->where('subject_id', $program->id)->count());
    }

    public function test_unlinking_keeps_vet_manager_and_other_client_managers(): void
    {
        Event::fake([ProgramTargetsChangedEvent::class]);
        $vetManager = $this->makeProfile(User::factory()->create(), 'vet', $this->vet->id, $this->vetManagerRole);
        $a = $this->clientProfile();
        $b = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id, $b->id]);
        $program = $this->makeProgram($this->establishment, [$vetManager, $a, $b]);

        $this->tenantPut([$b->guid])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$vetManager->id, $b->id],
            $program->managers()->pluck('user_profiles.id')->all(),
        );
    }

    public function test_unlinking_a_profile_that_manages_nothing_dispatches_no_regeneration(): void
    {
        Event::fake([ProgramTargetsChangedEvent::class]);
        $vetManager = $this->makeProfile(User::factory()->create(), 'vet', $this->vet->id, $this->vetManagerRole);
        $c = $this->clientProfile();
        $this->establishment->staff()->sync([$c->id]);
        $this->makeProgram($this->establishment, [$vetManager]);

        $this->tenantPut([])->assertOk();

        Event::assertNotDispatched(ProgramTargetsChangedEvent::class);
    }

    public function test_regeneration_failure_keeps_pivot_and_detach_consistent_and_does_not_return_500(): void
    {
        $a = $this->clientProfile();
        $b = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id, $b->id]);
        $program = $this->makeProgram($this->establishment, [$a, $b]);
        $this->seedTaskDueAlerts($program);

        Log::spy();
        Event::listen(ProgramTargetsChangedEvent::class, function () {
            throw new \RuntimeException('boom');
        });

        $this->tenantPut([$b->guid])->assertOk();

        $this->assertSame([$b->id], $this->establishment->staff()->pluck('user_profiles.id')->all());
        $this->assertSame([$b->id], $program->managers()->pluck('user_profiles.id')->all());
        // Regeneration was rolled back atomically: alerts still exist (stale) instead of being half-deleted.
        $this->assertGreaterThan(0, $this->pendingRecipients($program, $b));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_reconcile_command_detaches_unlinked_managers_and_regenerates_and_is_idempotent(): void
    {
        $a = $this->clientProfile();
        $b = $this->clientProfile();
        $this->establishment->staff()->sync([$b->id]); // A is NOT linked but is a manager (drift)
        $program   = $this->makeProgram($this->establishment, [$a, $b]);
        $cancelled = $this->makeProgram($this->establishment, [$a], cancelled: true);
        $this->seedTaskDueAlerts($program);

        Artisan::call('programs:reconcile-unlinked-managers', ['--dry-run' => true]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $program->managers()->pluck('user_profiles.id')->all());

        $this->assertSame(0, Artisan::call('programs:reconcile-unlinked-managers'));
        $this->assertSame([$b->id], $program->managers()->pluck('user_profiles.id')->all());
        $this->assertSame([$a->id], $cancelled->managers()->pluck('user_profiles.id')->all());
        $this->assertSame(0, $this->pendingRecipients($program, $a));
        $this->assertGreaterThan(0, $this->pendingRecipients($program, $b));

        // Second run: nothing to do.
        Event::fake([ProgramTargetsChangedEvent::class]);
        $this->assertSame(0, Artisan::call('programs:reconcile-unlinked-managers'));
        Event::assertNotDispatched(ProgramTargetsChangedEvent::class);
    }

    public function test_reconcile_command_regenerates_stale_alerts_left_by_a_failed_regeneration(): void
    {
        $a = $this->clientProfile();
        $b = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id, $b->id]);
        $program = $this->makeProgram($this->establishment, [$a, $b]);
        $this->seedTaskDueAlerts($program);

        // Simulate a failed regeneration: manager already detached, alerts still stale.
        $program->managers()->detach($a->id);
        $this->assertGreaterThan(0, $this->pendingRecipients($program, $a));

        Artisan::call('programs:reconcile-unlinked-managers');

        $this->assertSame(0, $this->pendingRecipients($program, $a));
        $this->assertGreaterThan(0, $this->pendingRecipients($program, $b));
    }

    public function test_sync_requires_establishments_update_permission_on_tenant_and_admin(): void
    {
        $a = $this->clientProfile();

        $this->actingAs($this->actorWithRole($this->assistantRole), 'sanctum')
            ->putJson($this->tenantUrl(), ['user_profile_guids' => [$a->guid]])
            ->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->assignRole('platform-viewer');
        $this->actingAs($viewer, 'sanctum')
            ->putJson($this->adminUrl(), ['user_profile_guids' => [$a->guid]])
            ->assertForbidden();

        $this->assertDatabaseCount('establishment_user_profile', 0);
    }

    public function test_admin_unlink_detaches_manager_from_active_program(): void
    {
        $a = $this->clientProfile();
        $b = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id, $b->id]);
        $program = $this->makeProgram($this->establishment, [$a, $b]);
        $this->seedTaskDueAlerts($program);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->adminUrl(), ['user_profile_guids' => [$b->guid]])
            ->assertOk();

        $this->assertSame([$b->id], $program->managers()->pluck('user_profiles.id')->all());
        $this->assertSame(0, $this->pendingRecipients($program, $a));
    }

    public function test_staff_index_exposes_establishments_guid_on_tenant_and_admin(): void
    {
        $a = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id]);

        $this->actingAs($this->actor, 'sanctum')
            ->getJson("/api/v1/vets/{$this->vet->guid}/clients/{$this->client->guid}/staff")
            ->assertOk()
            ->assertJsonPath('data.0.establishments.0.guid', $this->establishment->guid);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/clients/{$this->client->guid}/staff")
            ->assertOk()
            ->assertJsonPath('data.0.establishments.0.guid', $this->establishment->guid);
    }

    public function test_establishments_index_exposes_staff_only_with_clients_staff_read(): void
    {
        $a = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id]);

        // vet has clients.staff.read: staff (personal data) + staff_count.
        $this->actingAs($this->actor, 'sanctum')->getJson($this->establishmentsIndexUrl())
            ->assertOk()
            ->assertJsonPath('data.0.staff_count', 1)
            ->assertJsonPath('data.0.staff.0.guid', $a->guid)
            ->assertJsonPath('data.0.staff.0.user.email', $a->user->email);

        // vet-assistant only has establishments.read: staff_count only, no personal data.
        $response = $this->actingAs($this->actorWithRole($this->assistantRole), 'sanctum')
            ->getJson($this->establishmentsIndexUrl());
        $response->assertOk()->assertJsonPath('data.0.staff_count', 1);
        $this->assertArrayNotHasKey('staff', $response->json('data.0'));
        $this->assertStringNotContainsString($a->user->email, $response->getContent());

        // Admin with clients.staff.read also gets staff.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/clients/{$this->client->guid}/establishments")
            ->assertOk()->assertJsonPath('data.0.staff.0.guid', $a->guid);

        // Admin without clients.staff.read must not get personal data either.
        $viewer = User::factory()->create();
        $viewer->assignRole('platform-viewer');
        $adminResponse = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/admin/clients/{$this->client->guid}/establishments");
        $adminResponse->assertOk()->assertJsonPath('data.0.staff_count', 1);
        $this->assertArrayNotHasKey('staff', $adminResponse->json('data.0'));
    }

    public function test_no_unlink_event_when_nothing_detached(): void
    {
        Event::fake([EstablishmentStaffUnlinkedEvent::class]);
        $a = $this->clientProfile();

        $this->tenantPut([$a->guid])->assertOk();
        Event::assertNotDispatched(EstablishmentStaffUnlinkedEvent::class);

        $this->tenantPut([])->assertOk();
        Event::assertDispatched(EstablishmentStaffUnlinkedEvent::class);
    }

    public function test_deleting_profile_or_establishment_cleans_pivot(): void
    {
        $a = $this->clientProfile();
        $b = $this->clientProfile();
        $this->establishment->staff()->sync([$a->id, $b->id]);

        $a->delete();
        $this->assertDatabaseCount('establishment_user_profile', 1);

        $this->establishment->delete();
        $this->assertDatabaseCount('establishment_user_profile', 0);
    }

    private function makeProgram(Establishment $est, array $managers, bool $cancelled = false): Program
    {
        $program = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $this->vet->id, 'client_id' => $this->client->id,
            'establishment_id' => $est->id, 'technique_id' => $this->technique->id, 'protocol_id' => $this->protocol->id,
            'cancelled_at' => $cancelled ? now() : null,
        ]);
        $program->managers()->sync(array_map(fn ($m) => $m->id, $managers));

        return $program;
    }

    /** Gives the program a future target and a task_due protocol alert, then generates the real alerts. */
    private function seedTaskDueAlerts(Program $program): void
    {
        if (!$this->taskAlertSeeded) {
            $task = ProtocolTask::create([
                'guid' => Str::uuid()->toString(), 'protocol_id' => $this->protocol->id,
                'description' => 'Tarea', 'days_offset' => 0, 'time_of_day' => 'after',
                'time' => '08:00', 'important' => false, 'sort_order' => 1,
            ]);
            ProtocolTaskAlert::create([
                'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id,
                'offset_days' => 0, 'time_of_day' => 'after', 'time' => '08:30',
                'roles' => ['client-owner', 'client-manager', 'vet-manager'], 'message' => 'Recordatorio',
                'require_confirmation' => false, 'sort_order' => 1,
            ]);
            $this->taskAlertSeeded = true;
        }

        ProgramTarget::create(['program_id' => $program->id, 'target_date' => now()->addDays(30)->toDateString()]);
        event(new ProgramTargetsChangedEvent($program->fresh()));
    }

    private function pendingRecipients(Program $program, UserProfile $profile): int
    {
        return AlertRecipient::where('user_profile_id', $profile->id)
            ->whereHas('alert', fn ($q) => $q
                ->where('type', AlertType::ProgramTaskDue)
                ->where('subject_type', 'program')
                ->where('subject_id', $program->id)
                ->where('status', 'pending'))
            ->count();
    }
}
