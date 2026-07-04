<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->string('authenticatable_type', 200);
            $table->unsignedBigInteger('authenticatable_id');
            $table->foreignId('role_id')
                  ->constrained('roles')
                  ->cascadeOnDelete();
            $table->timestamps();

            // Índice compuesto para resolver perfil dado usuario + tenant
            $table->index(['user_id', 'authenticatable_type', 'authenticatable_id'], 'up_user_auth_index');
            // Índice para queries polimórficas (morphMany desde Vet)
            $table->index(['authenticatable_type', 'authenticatable_id'], 'up_auth_index');
            // Unicidad: un usuario no puede tener dos perfiles en el mismo tenant
            $table->unique(['user_id', 'authenticatable_type', 'authenticatable_id'], 'up_user_auth_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
