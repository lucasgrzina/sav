<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->string('contactable_type', 200);
            $table->unsignedBigInteger('contactable_id');
            $table->string('type', 20);  // casteado a ContactType enum en el modelo
            $table->string('label', 100)->nullable();
            $table->string('value', 200);
            $table->boolean('is_primary')->default(false);
            $table->boolean('use_for_alerts')->default(false);
            $table->timestamps();

            // Índice compuesto principal: soporte para la regla is_primary y queries de alertas
            $table->index(['contactable_type', 'contactable_id', 'type']);
            // Índice para queries polimórficas (morphMany)
            $table->index(['contactable_type', 'contactable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
