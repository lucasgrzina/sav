<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_plan_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('vet_id')->nullable()->after('health_plan_category_id')
                  ->comment('null = plantilla global (catálogo super-admin); no nulo = plantilla propia del vet');

            $table->foreign('vet_id')
                  ->references('id')
                  ->on('vets')
                  ->cascadeOnDelete();

            $table->index('vet_id');
        });
    }

    public function down(): void
    {
        Schema::table('health_plan_templates', function (Blueprint $table) {
            $table->dropForeign(['vet_id']);
            $table->dropColumn('vet_id');
        });
    }
};
