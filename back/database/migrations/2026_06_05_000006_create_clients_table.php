<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->string('name', 150);
            $table->foreignId('country_id')
                  ->constrained('countries')
                  ->cascadeOnDelete();
            $table->foreignId('document_type_id')
                  ->constrained('document_types')
                  ->cascadeOnDelete();
            $table->string('tax_id', 50);
            $table->string('address', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->timestamps();

            // Índice para búsqueda por name y tax_id (filtro search del listado)
            $table->index('name');
            $table->index('tax_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
