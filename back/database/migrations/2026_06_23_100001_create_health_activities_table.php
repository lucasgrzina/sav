<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_activities', function (Blueprint $table) {
            $table->id();
            $table->string('guid', 36)->unique();
            $table->string('name', 255);
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_activities');
    }
};
