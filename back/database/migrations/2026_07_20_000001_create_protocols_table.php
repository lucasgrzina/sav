<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocols', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique()->comment('UUID generado por HasGuid trait');
            $table->unsignedBigInteger('technique_id')->comment('Siempre una sub-técnica (parent_id NOT NULL), nunca la raíz');
            $table->unsignedBigInteger('country_id')->nullable()->comment('null = protocolo global, visible en todos los países');
            $table->unsignedBigInteger('vet_id')->nullable()->comment('null en esta iteración (solo SuperAdmin); reservado para protocolos propios de un vet');
            $table->string('created_by_type', 20)->default('superadmin')->comment("'superadmin' | 'vet'");
            $table->unsignedBigInteger('created_by_id')->comment('id interno del usuario autor; no se expone en el Resource, solo auditoría');
            $table->string('name', 255);
            $table->string('color', 20)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('technique_id')->references('id')->on('techniques')->restrictOnDelete();
            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('vet_id')->references('id')->on('vets')->nullOnDelete();

            $table->unique(['technique_id', 'country_id', 'name'], 'protocols_technique_country_name_unique');
            $table->index('created_by_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocols');
    }
};
