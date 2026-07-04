<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')
                  ->constrained('notifications')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->timestamp('read_at')->nullable()->comment('NULL = no leída');
            $table->timestamps();

            $table->unique(['notification_id', 'user_id']);
            $table->index('user_id');
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
