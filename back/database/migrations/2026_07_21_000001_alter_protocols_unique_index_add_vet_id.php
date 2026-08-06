<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Se agrega el nuevo índice ANTES de eliminar el viejo: technique_id tiene una FK y
        // MySQL exige que exista en todo momento un índice que cubra esa columna. Si se
        // eliminara primero, quedaría un instante sin ningún índice sobre technique_id y MySQL
        // rechaza el DROP con error 1553 ("needed in a foreign key constraint").
        Schema::table('protocols', function (Blueprint $table) {
            $table->unique(['technique_id', 'country_id', 'vet_id', 'name'], 'protocols_technique_country_vet_name_unique');
        });

        Schema::table('protocols', function (Blueprint $table) {
            $table->dropUnique('protocols_technique_country_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('protocols', function (Blueprint $table) {
            $table->unique(['technique_id', 'country_id', 'name'], 'protocols_technique_country_name_unique');
        });

        Schema::table('protocols', function (Blueprint $table) {
            $table->dropUnique('protocols_technique_country_vet_name_unique');
        });
    }
};
