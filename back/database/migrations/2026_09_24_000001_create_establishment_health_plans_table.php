<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishment_health_plans', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('vet_id')->constrained('vets')->cascadeOnDelete()
                  ->comment('tenant owner — regla dura #4');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('health_plan_template_id')->constrained('health_plan_templates')->restrictOnDelete()
                  ->comment('solo trazabilidad — el calendario ya está materializado, no se re-lee (DEC-05)');
            $table->unsignedSmallInteger('year')->comment('año de inicio del ciclo ganadero (ej. 2026 = jul/2026-jun/2027 en AR)');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()
                  ->comment('auditoría — quién instanció el plan (regla dura #7 / NFR auditoría)');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('vet_id');
            $table->index(['vet_id', 'cancelled_at']);
            $table->index(['establishment_id', 'health_plan_template_id', 'year'], 'ehp_dedup_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_health_plans');
    }
};
