<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Un registro de preferencias por usuario');
            $table->string('theme_mode')->default('dark')->comment('Modo de tema: dark | light');
            $table->string('theme_palette')->default('green')->comment('Paleta de color: green | blue | red | yellow');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
