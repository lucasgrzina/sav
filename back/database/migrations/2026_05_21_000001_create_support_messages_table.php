<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique()->comment('UUID generado automáticamente por HasGuid trait');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 255);
            $table->text('body');
            $table->enum('status', ['open', 'in_progress', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->enum('category', ['bug', 'question', 'feature_request', 'other'])->default('other');
            $table->text('admin_response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
