<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_message_replies', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('support_message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('support_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_message_replies');
    }
};
