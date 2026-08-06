<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_task_alerts', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->unsignedBigInteger('protocol_task_id');
            $table->integer('offset_days')->default(0)->comment('Cantidad de días respecto a la fecha de la tarea (siempre >= 0); la dirección la da time_of_day');
            $table->string('time_of_day', 10)->default('before')->comment("'before' | 'after' — dirección respecto a la fecha de la tarea");
            $table->time('time');
            $table->json('roles')->comment('Array de nombres de roles tenant, mínimo 1');
            $table->text('message');
            $table->boolean('require_confirmation')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('protocol_task_id')->references('id')->on('protocol_tasks')->cascadeOnDelete();
            $table->index(['protocol_task_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_task_alerts');
    }
};
