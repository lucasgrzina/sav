<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('techniques', function (Blueprint $table) {
            $table->id();
            $table->string('guid', 36)->unique();
            $table->string('name', 255);
            $table->string('target_date_name', 255)->nullable();
            $table->string('type', 50)->default('technique'); // 'technique' | 'vaccine'
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('protocols_name', 255)->nullable();
            $table->timestamps();

            $table->foreign('parent_id')
                  ->references('id')
                  ->on('techniques')
                  ->nullOnDelete();

            $table->index('type');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('techniques');
    }
};
