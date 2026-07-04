<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_plan_templates', function (Blueprint $table) {
            $table->id();
            $table->string('guid', 36)->unique();
            $table->string('name', 255);
            $table->unsignedBigInteger('health_plan_category_id');
            $table->timestamps();

            $table->foreign('health_plan_category_id')
                  ->references('id')
                  ->on('health_plan_categories')
                  ->cascadeOnDelete();

            $table->index('health_plan_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_plan_templates');
    }
};
