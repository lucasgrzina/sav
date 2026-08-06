<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            TechniquePermissionsSeeder::class,
            ProtocolPermissionsSeeder::class,
            ProgramPermissionsSeeder::class,
            SystemSettingSeeder::class,
            CountrySeeder::class,
            HealthPlanSeeder::class,
        ]);

        $user = User::factory()->create([
            'guid'       => Str::uuid()->toString(),
            'first_name' => 'Test',
            'last_name'  => 'User',
            'name'       => 'Test User',
            'email'      => 'test@example.com',
        ]);

        $user->assignRole('super-admin');

        $this->call([
            TestDataSeeder::class,
            MoetProtocolSeeder::class,
        ]);
    }
}
