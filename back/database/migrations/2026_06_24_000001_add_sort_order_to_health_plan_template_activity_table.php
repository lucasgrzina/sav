<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_plan_template_activity', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('months');
        });
    }

    public function down(): void
    {
        Schema::table('health_plan_template_activity', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
