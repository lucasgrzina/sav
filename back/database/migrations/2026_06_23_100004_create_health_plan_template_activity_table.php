<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_plan_template_activity', function (Blueprint $table) {
            $table->foreignId('health_plan_template_id')
                  ->constrained('health_plan_templates')
                  ->cascadeOnDelete();
            $table->foreignId('health_activity_id')
                  ->constrained('health_activities')
                  ->cascadeOnDelete();
            $table->json('months');  // array de enteros [1,3,6,9]

            $table->primary(['health_plan_template_id', 'health_activity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_plan_template_activity');
    }
};
