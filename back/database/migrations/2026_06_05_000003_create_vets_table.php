<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vets', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->foreignId('country_id')
                  ->constrained('countries')
                  ->cascadeOnDelete();
            $table->foreignId('document_type_id')
                  ->constrained('document_types')
                  ->cascadeOnDelete();
            $table->string('tax_id', 50);
            $table->string('registration_number', 50)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->string('pdf_title', 200)->nullable();
            $table->string('pdf_subtitle', 200)->nullable();
            $table->timestamps();

            // Índice para búsqueda de tenant por slug (usado en middleware)
            $table->index('slug');
            // Índice para listar vets activas
            $table->index(['validated_at', 'suspended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vets');
    }
};
