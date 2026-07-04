<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'code'        => 'password_expiration_months',
                'value'       => '3',
                'type'        => 'integer',
                'description' => 'Meses de vigencia del password. 0 = deshabilitado.',
            ],
            [
                'code'        => 'password_history_count',
                'value'       => '5',
                'type'        => 'integer',
                'description' => 'Cantidad de passwords anteriores que no se pueden reutilizar. 0 = deshabilitado.',
            ],
        ];

        foreach ($settings as $setting) {
            SystemSetting::firstOrCreate(
                ['code' => $setting['code']],
                [
                    'value'       => $setting['value'],
                    'type'        => $setting['type'],
                    'description' => $setting['description'],
                ],
            );
        }
    }
}
