<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('client_id')
                  ->constrained('clients')
                  ->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('city', 100)->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamps();

            // Índice para listado de establecimientos por client
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
