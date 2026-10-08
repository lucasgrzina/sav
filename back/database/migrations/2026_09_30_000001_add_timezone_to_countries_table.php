<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** iso_code => main IANA timezone (countries with several zones use the principal one). */
    private const TIMEZONES = [
        'AR' => 'America/Argentina/Buenos_Aires',
        'UY' => 'America/Montevideo',
        'MX' => 'America/Mexico_City',
        'PE' => 'America/Lima',
        'CO' => 'America/Bogota',
        'CL' => 'America/Santiago',
        'BR' => 'America/Sao_Paulo',
    ];

    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->after('phone_prefix')
                ->comment('IANA timezone used to interpret local alert times');
        });

        foreach (self::TIMEZONES as $isoCode => $timezone) {
            DB::table('countries')->where('iso_code', $isoCode)->update(['timezone' => $timezone]);
        }
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
