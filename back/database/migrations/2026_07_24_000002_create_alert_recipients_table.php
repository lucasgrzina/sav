<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_recipients', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('alert_id')
                  ->constrained('alerts')
                  ->cascadeOnDelete();
            $table->foreignId('user_profile_id')
                  ->constrained('user_profiles')
                  ->cascadeOnDelete();
            $table->string('channel');
            $table->string('status')->default('pending');
            $table->string('provider_message_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->unique(['alert_id', 'user_profile_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_recipients');
    }
};
