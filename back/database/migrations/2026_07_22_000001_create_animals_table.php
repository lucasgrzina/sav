<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animals', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('client_id')
                  ->constrained('clients')
                  ->cascadeOnDelete();
            $table->foreignId('establishment_id')
                  ->nullable()
                  ->constrained('establishments')
                  ->nullOnDelete();
            $table->string('rp', 50)->comment('Identificación de rodeo (RP), reemplaza rp_donor legacy. Único por cliente, no globalmente (ver constraint abajo).');
            $table->string('name', 150)->nullable()->comment('Uso futuro: nombre de mascota (HealthPlan)');
            $table->string('type', 20)->default('livestock')->comment("'livestock' | 'pet' — solo 'livestock' se usa en este módulo");
            $table->timestamps();

            $table->index(['client_id', 'type']);
            $table->unique(['client_id', 'rp'], 'animals_client_id_rp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animals');
    }
};
