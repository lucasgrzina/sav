<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_vet', function (Blueprint $table) {
            $table->foreignId('client_id')
                  ->constrained('clients')
                  ->cascadeOnDelete();
            $table->foreignId('vet_id')
                  ->constrained('vets')
                  ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['client_id', 'vet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_vet');
    }
};
