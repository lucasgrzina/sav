<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique()->comment('UUID generado automáticamente por HasGuid trait');
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('name')->comment('Nombre completo: first_name + last_name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->string('verification_code', 6)->nullable()->comment('Código de 6 dígitos para verificar email');
            $table->timestamp('verification_code_expires_at')->nullable();
            $table->string('verification_link_token', 64)->nullable();
            $table->timestamp('verification_link_expires_at')->nullable();
            $table->timestamp('last_verification_email_sent_at')->nullable()->comment('Controla cooldown de reenvío');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
