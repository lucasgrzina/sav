<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_alert_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            // No FK on purpose: the user may be deleted, this column is audit-only.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('trigger', 40)->comment('program_created|program_updated|program_cancelled|managers_unlinked|protocol_updated|manual_rebuild|nightly|command');
            $table->json('counts')->comment('kept, updated, created, cancelled by reason, untouched_dispatched, skipped_past, adopted, recipients');
            $table->boolean('aborted')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['program_id', 'created_at'], 'par_program_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_alert_reconciliations');
    }
};
