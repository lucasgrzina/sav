<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->string('renspa', 50)->nullable()->after('name');
            $table->string('address', 255)->nullable()->after('renspa');
            $table->string('state', 100)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn(['renspa', 'address', 'state']);
        });
    }
};
