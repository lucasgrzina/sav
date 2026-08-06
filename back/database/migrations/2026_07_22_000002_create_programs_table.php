<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('vet_id')
                  ->constrained('vets')
                  ->cascadeOnDelete();
            $table->foreignId('client_id')
                  ->constrained('clients')
                  ->cascadeOnDelete();
            $table->foreignId('establishment_id')
                  ->constrained('establishments')
                  ->cascadeOnDelete();
            $table->foreignId('technique_id')
                  ->constrained('techniques')
                  ->restrictOnDelete();
            $table->foreignId('protocol_id')
                  ->constrained('protocols')
                  ->restrictOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('vet_id');
            $table->index(['vet_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
