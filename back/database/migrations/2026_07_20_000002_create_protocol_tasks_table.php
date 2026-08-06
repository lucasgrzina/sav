<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_tasks', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->unsignedBigInteger('protocol_id');
            $table->text('description');
            $table->integer('days_offset')->comment('Cantidad de días (siempre >= 0); la dirección la da time_of_day');
            $table->string('time_of_day', 10)->comment("'before' | 'after' — dirección respecto a la fecha objetivo");
            $table->time('time');
            $table->boolean('important')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('protocol_id')->references('id')->on('protocols')->cascadeOnDelete();
            $table->index(['protocol_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_tasks');
    }
};
