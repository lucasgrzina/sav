<?php

namespace Database\Seeders;

use App\Models\HealthActivity;
use App\Models\HealthPlanCategory;
use App\Models\HealthPlanTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class HealthPlanSeeder extends Seeder
{
    public function run(): void
    {
        $activities = [
            'COMPLEJO REPRODUCTIVO',
            'COMPLEJO DIARREA NEONATAL',
            'QUERATOCONJUNTIVITIS',
            'CARBUNCLO',
            'AFTOSA',
            'TRATAMIENTO PIOJO',
            'COMPLEJO VIT-MIN',
            'MOSQUICIDA',
            'ECOGRAFIA PREÑEZ',
            'SANGRADO BRUCELOSIS',
            'SERVICIO DE PRIMAVERA',
            'DESTETE',
            'REPRODUCTIVA',
            'DESPARACITACION',
            'SERVICIO DE OTOÑO',
            'COMPLEJO RESPIRATORIO',
            'CLOSTRIDIAL',
            'BRUCELOSIS',
            'DESPARASITACION',
            'VITAMINA ADE',
            'SERVICIO',
            'RASPAJE',
        ];

        foreach ($activities as $activity) {
            HealthActivity::firstOrCreate(
                ['name' => $activity],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $categories = ['VACAS', 'VAQUILLAS', 'TERNEROS/AS', 'TOROS'];

        foreach ($categories as $category) {
            HealthPlanCategory::firstOrCreate(
                ['name' => $category],
                ['guid' => Str::uuid()->toString()],
            );
        }

        $vacasId = HealthPlanCategory::where('name', 'VACAS')->value('id');

        $healthPlanTemplate = HealthPlanTemplate::firstOrCreate(
            ['name' => 'Plan sanitario para vacas'],
            [
                'guid'                    => Str::uuid()->toString(),
                'health_plan_category_id' => $vacasId,
            ],
        );

        // months como array; sort_order preserva el orden del legacy
        $templateActivities = [
            'COMPLEJO REPRODUCTIVO'     => [10],
            'COMPLEJO DIARREA NEONATAL' => [6],
            'QUERATOCONJUNTIVITIS'      => [],
            'CARBUNCLO'                 => [3, 11],
            'AFTOSA'                    => [3],
            'TRATAMIENTO PIOJO'         => [6, 7],
            'COMPLEJO VIT-MIN'          => [4, 10],
            'MOSQUICIDA'                => [1, 11, 12],
            'ECOGRAFIA PREÑEZ'          => [3],
            'SANGRADO BRUCELOSIS'       => [4],
            'SERVICIO DE PRIMAVERA'     => [10, 11, 12],
            'DESTETE'                   => [3, 4],
        ];

        $sortOrder = 0;
        foreach ($templateActivities as $name => $months) {
            $activity = HealthActivity::where('name', $name)->first();
            $healthPlanTemplate->activities()->syncWithoutDetaching([
                $activity->id => [
                    'months'     => json_encode($months),
                    'sort_order' => $sortOrder++,
                ],
            ]);
        }
    }
}
