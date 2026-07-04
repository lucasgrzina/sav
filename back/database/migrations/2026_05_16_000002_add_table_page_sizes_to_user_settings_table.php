<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->json('table_page_sizes')
                ->nullable()
                ->default(null)
                ->comment('Mapa de tableKey => perPage por tabla paginada')
                ->after('theme_palette');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('table_page_sizes');
        });
    }
};
