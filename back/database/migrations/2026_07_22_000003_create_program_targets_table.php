<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_targets', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('program_id')
                  ->constrained('programs')
                  ->cascadeOnDelete();
            $table->date('target_date');
            $table->timestamps();

            $table->index(['program_id', 'target_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_targets');
    }
};
