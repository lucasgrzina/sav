<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_uuid', 36)->nullable()
                  ->comment('uuid de la SUSCRIPCION (lo que manda mobile como "uuid" en el POST), no del dispositivo — informativo, no es clave de unicidad. Ver D3 en Riesgos.');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique()
                  ->comment('sha256(endpoint) — clave natural real para upsert/unicidad; endpoint es TEXT y no puede indexarse directo');
            $table->string('p256dh');
            $table->string('auth_key')->comment('Push API auth secret (keys.auth) — renombrado para no chocar con el término "auth" del framework');
            $table->string('content_encoding')->default('aes128gcm');
            $table->string('device_label')->nullable();
            $table->timestamp('device_updated_at')->nullable()
                  ->comment('updated_at reportado por el cliente al crear/tocar la suscripción — distinto del updated_at propio de la fila');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
