<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillEstablishmentUserProfileMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_29_000002_backfill_establishment_user_profile.php');
        $migration->up();
    }

    public function test_backfill_links_client_staff_to_all_establishments_of_their_client_only(): void
    {
        $country = Country::create(['guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54']);
        $doc     = DocumentType::create(['guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*']);

        $mkClient = fn (string $tax) => Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'C ' . $tax, 'country_id' => $country->id,
            'document_type_id' => $doc->id, 'tax_id' => $tax,
        ]);
        $mkEst = fn (Client $c, string $n) => Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $c->id, 'name' => $n]);
        $mkRole = fn (string $n) => Role::firstOrCreate(
            ['name' => $n, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
        $mkProfile = fn (string $type, int $id, Role $role) => UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => User::factory()->create()->id,
            'authenticatable_type' => $type, 'authenticatable_id' => $id, 'role_id' => $role->id,
        ]);

        $c1 = $mkClient('1');
        $c2 = $mkClient('2');
        $e1 = $mkEst($c1, 'E1');
        $e2 = $mkEst($c1, 'E2');
        $e3 = $mkEst($c2, 'E3');

        $vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'V', 'slug' => 'v-' . Str::random(6),
            'country_id' => $country->id, 'document_type_id' => $doc->id, 'tax_id' => '9', 'validated_at' => now(),
        ]);

        $owner1  = $mkProfile('client', $c1->id, $mkRole('client-owner'));
        $admin2  = $mkProfile('client', $c2->id, $mkRole('client-administrative'));
        $vetProf = $mkProfile('vet', $vet->id, $mkRole('vet'));
        // Same numeric id as a client but different authenticatable type: must not be linked.
        $vetSameId = $mkProfile('vet', $c1->id, $mkRole('vet-assistant'));

        $this->runBackfill();

        $pairs = DB::table('establishment_user_profile')->get()
            ->map(fn ($r) => $r->establishment_id . ':' . $r->user_profile_id)->sort()->values()->all();
        $expected = collect([
            $e1->id . ':' . $owner1->id,
            $e2->id . ':' . $owner1->id,
            $e3->id . ':' . $admin2->id,
        ])->sort()->values()->all();

        $this->assertSame($expected, $pairs);
        $this->assertFalse($vetProf->establishments()->exists());
        $this->assertFalse($vetSameId->establishments()->exists());

        // Idempotent
        $this->runBackfill();
        $this->assertSame(3, DB::table('establishment_user_profile')->count());
    }

    public function test_backfill_completes_a_partial_pivot_without_duplicates_and_skips_non_staff_client_roles(): void
    {
        $country = Country::create(['guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54']);
        $doc     = DocumentType::create(['guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*']);
        $client  = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'C', 'country_id' => $country->id,
            'document_type_id' => $doc->id, 'tax_id' => '1',
        ]);
        $mkEst  = fn (string $n) => Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $client->id, 'name' => $n]);
        $mkRole = fn (string $n) => Role::firstOrCreate(
            ['name' => $n, 'guard_name' => 'web'],
            ['guid' => Str::uuid()->toString(), 'type' => Role::TYPE_TENANT],
        );
        $mkProfile = fn (Role $role) => UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => User::factory()->create()->id,
            'authenticatable_type' => 'client', 'authenticatable_id' => $client->id, 'role_id' => $role->id,
        ]);

        $e1 = $mkEst('E1');
        $e2 = $mkEst('E2');
        $staff    = $mkProfile($mkRole('client-manager'));
        $nonStaff = $mkProfile($mkRole('client-other')); // client profile with a role outside CLIENT_STAFF_ROLES

        // Partial pre-existing pivot: (e1, staff) already linked.
        $e1->staff()->attach($staff->id);

        $this->runBackfill();

        $pairs = DB::table('establishment_user_profile')->get()
            ->map(fn ($r) => $r->establishment_id . ':' . $r->user_profile_id)->sort()->values()->all();

        $this->assertSame(
            collect([$e1->id . ':' . $staff->id, $e2->id . ':' . $staff->id])->sort()->values()->all(),
            $pairs,
        );
        $this->assertFalse($nonStaff->establishments()->exists());
    }
}
