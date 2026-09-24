<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishment_health_plan_activities', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('establishment_health_plan_id')
                  ->constrained('establishment_health_plans', 'id', 'ehp_activities_plan_id_foreign')
                  ->cascadeOnDelete();
            $table->foreignId('health_activity_id')
                  ->constrained('health_activities', 'id', 'ehp_activities_health_activity_id_foreign')
                  ->restrictOnDelete();
            $table->unsignedTinyInteger('month')->comment('1-12, mes calendario — copiado del pivot months del template al instanciar (DEC-05)');
            $table->date('due_date')->comment('fecha concreta calculada al instanciar via App\\Support\\HealthPlanYear');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('require_confirmation')->default(true)->comment('regla dura #7 — siempre true en Fase 1, columna explícita para no hardcodear en código');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_profile_id')->nullable()
                  ->constrained('user_profiles', 'id', 'ehp_activities_confirmed_by_profile_id_foreign')
                  ->nullOnDelete()
                  ->comment('regla dura #7 — quién confirmó');
            $table->timestamps();

            $table->index(['establishment_health_plan_id', 'month'], 'ehp_activities_plan_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_health_plan_activities');
    }
};
