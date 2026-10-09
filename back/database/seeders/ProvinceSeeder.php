<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Province;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProvinceSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Argentine jurisdictions: CABA + 23 provinces. */
    private const ARGENTINA = [
        'Ciudad Autónoma de Buenos Aires',
        'Buenos Aires',
        'Catamarca',
        'Chaco',
        'Chubut',
        'Córdoba',
        'Corrientes',
        'Entre Ríos',
        'Formosa',
        'Jujuy',
        'La Pampa',
        'La Rioja',
        'Mendoza',
        'Misiones',
        'Neuquén',
        'Río Negro',
        'Salta',
        'San Juan',
        'San Luis',
        'Santa Cruz',
        'Santa Fe',
        'Santiago del Estero',
        'Tierra del Fuego, Antártida e Islas del Atlántico Sur',
        'Tucumán',
    ];

    /** Idempotent: safe to run on existing environments. */
    public function run(): void
    {
        $argentina = Country::where('iso_code', 'AR')->first();

        if (!$argentina) {
            return;
        }

        foreach (self::ARGENTINA as $name) {
            Province::firstOrCreate(
                ['country_id' => $argentina->id, 'name' => $name],
                ['guid' => Str::uuid()->toString()],
            );
        }
    }
}
