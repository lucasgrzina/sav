<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_targets', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('target_date')
                  ->comment('Optional group name, used by the [grupo] alert placeholder');
        });
    }

    public function down(): void
    {
        Schema::table('program_targets', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
