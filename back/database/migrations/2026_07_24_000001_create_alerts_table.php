<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->string('type')->comment('AlertType enum value, never a FQCN');
            $table->nullableMorphs('subject');
            $table->json('payload');
            $table->timestamp('scheduled_at');
            $table->string('status')->default('pending');
            $table->boolean('require_confirmation')->default(false);
            $table->foreignId('vet_id')
                  ->nullable()
                  ->constrained('vets')
                  ->cascadeOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
