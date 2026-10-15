<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/dashboard';

    private int $profileSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $permission = Permission::firstOrCreate(
            ['name' => 'dashboard.admin.read', 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString()],
        );

        foreach (['super-admin', 'admin'] as $name) {
            $this->role($name, Role::TYPE_PLATFORM)->givePermissionTo($permission);
        }
        $this->role('operador', Role::TYPE_PLATFORM);
        $this->role('vet', Role::TYPE_TENANT);

        // Raw fixtures: FKs are irrelevant to the aggregates under test (deferred; the test transaction rolls back).
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    private function role(string $name, string $type): Role
    {
        return Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => $type],
        );
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function vet(?string $validatedAt, ?string $suspendedAt = null): void
    {
        $uuid = Str::uuid()->toString();
        DB::table('vets')->insert([
            'guid' => $uuid, 'name' => 'Vet ' . $uuid, 'slug' => $uuid, 'country_id' => 1,
            'document_type_id' => 1, 'tax_id' => '1', 'validated_at' => $validatedAt,
            'suspended_at' => $suspendedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function recipient(string $channel, string $status, ?string $sentAt): void
    {
        $uuid = Str::uuid()->toString();
        DB::table('alert_recipients')->insert([
            'guid' => $uuid, 'alert_id' => 1, 'user_profile_id' => ++$this->profileSeq, 'channel' => $channel,
            'status' => $status, 'sent_at' => $sentAt, 'idempotency_key' => $uuid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function program(?string $cancelledAt, string $targetDate): void
    {
        $id = DB::table('programs')->insertGetId([
            'guid' => Str::uuid()->toString(), 'vet_id' => 1, 'client_id' => 1, 'establishment_id' => 1,
            'technique_id' => 1, 'protocol_id' => 1, 'cancelled_at' => $cancelledAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('program_targets')->insert([
            'guid' => Str::uuid()->toString(), 'program_id' => $id, 'target_date' => $targetDate,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_returns_full_shape_for_super_admin_and_admin(): void
    {
        foreach (['super-admin', 'admin'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->getJson(self::URL)
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonStructure(['data' => [
                    'generated_at',
                    'vets' => ['active', 'pending', 'suspended', 'total'],
                    'totals' => ['clients', 'establishments', 'animals', 'active_programs'],
                    'sent_last_7d' => [['date', 'count']],
                    'delivery_30d' => ['rate', 'by_channel'],
                ]])
                ->assertJsonCount(7, 'data.sent_last_7d');
        }
    }

    public function test_rejects_tenant_role_operador_and_anonymous(): void
    {
        $this->actingAs($this->userWithRole('vet'), 'sanctum')->getJson(self::URL)->assertForbidden();
        $this->actingAs($this->userWithRole('operador'), 'sanctum')->getJson(self::URL)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_counts_vets_by_state(): void
    {
        $this->vet(now()->toDateTimeString());
        $this->vet(null);
        $this->vet(now()->toDateTimeString(), now()->toDateTimeString());

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->getJson(self::URL)
            ->assertJsonPath('data.vets', ['active' => 1, 'pending' => 1, 'suspended' => 1, 'total' => 3]);
    }

    public function test_excludes_cancelled_and_expired_programs(): void
    {
        $this->program(null, now()->addDays(5)->toDateString());
        $this->program(now()->toDateTimeString(), now()->addDays(5)->toDateString());
        $this->program(null, now()->subDays(5)->toDateString());

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->getJson(self::URL)
            ->assertJsonPath('data.totals.active_programs', 1);
    }

    public function test_rate_is_null_without_recipients(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->getJson(self::URL)
            ->assertJsonPath('data.delivery_30d.rate', null)
            ->assertJsonPath('data.delivery_30d.by_channel', []);
    }

    public function test_delivery_rate_excludes_pending_and_suppressed_and_counts_sent_per_day(): void
    {
        $now = now()->utc()->toDateTimeString();
        $this->recipient('whatsapp', 'delivered', $now);
        $this->recipient('whatsapp', 'read', $now);
        $this->recipient('whatsapp', 'sent', $now);
        $this->recipient('whatsapp', 'failed', $now);
        $this->recipient('whatsapp', 'pending', null);
        $this->recipient('whatsapp', 'suppressed', $now);
        $this->recipient('email', 'delivered', $now);

        $response = $this->actingAs($this->userWithRole('admin'), 'sanctum')->getJson(self::URL)->assertOk();

        $channels = collect($response->json('data.delivery_30d.by_channel'))->keyBy('channel');
        $this->assertEquals(50.0, $channels['whatsapp']['rate']);
        $this->assertEquals(100.0, $channels['email']['rate']);
        // 3 ok of 5 attempts overall = 60.0
        $this->assertEquals(60.0, $response->json('data.delivery_30d.rate'));
        // sent/delivered/read only: 3 whatsapp + 1 email today
        $this->assertSame(4, $response->json('data.sent_last_7d.6.count'));
    }
}
