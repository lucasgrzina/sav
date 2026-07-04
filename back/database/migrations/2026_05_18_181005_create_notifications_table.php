<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique()->comment('UUID generado automáticamente por HasGuid trait');
            $table->json('payload')->comment('Estructura libre: {title, description, url, type, ...}');
            $table->unsignedBigInteger('created_by')->nullable()->comment('ID del usuario que generó la notificación; null si fue el sistema');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
