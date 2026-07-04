<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\DocumentType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CountrySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Argentina
        $argentina = Country::firstOrCreate(
            ['iso_code' => 'AR'],
            [
                'guid'         => Str::uuid()->toString(),
                'name'         => 'Argentina',
                'phone_prefix' => '54',
            ]
        );

        DocumentType::firstOrCreate(
            ['country_id' => $argentina->id, 'name' => 'CUIT'],
            [
                'guid'             => Str::uuid()->toString(),
                'validation_regex' => '^\d{2}-\d{8}-\d{1}$',
            ]
        );

        DocumentType::firstOrCreate(
            ['country_id' => $argentina->id, 'name' => 'CUIL'],
            [
                'guid'             => Str::uuid()->toString(),
                'validation_regex' => '^\d{2}-\d{8}-\d{1}$',
            ]
        );

        // Uruguay
        $uruguay = Country::firstOrCreate(
            ['iso_code' => 'UY'],
            [
                'guid'         => Str::uuid()->toString(),
                'name'         => 'Uruguay',
                'phone_prefix' => '598',
            ]
        );

        DocumentType::firstOrCreate(
            ['country_id' => $uruguay->id, 'name' => 'RUT'],
            [
                'guid'             => Str::uuid()->toString(),
                'validation_regex' => '^\d{12}$',
            ]
        );
    }
}
