<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorials', function (Blueprint $table) {
            $table->id();
            $table->string('guid', 36)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('source', ['youtube', 'vimeo']);
            $table->string('code');
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorials');
    }
};
