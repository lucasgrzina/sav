<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_target_animal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_target_id')
                  ->constrained('program_targets')
                  ->cascadeOnDelete();
            $table->foreignId('animal_id')
                  ->constrained('animals')
                  ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['program_target_id', 'animal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_target_animal');
    }
};
