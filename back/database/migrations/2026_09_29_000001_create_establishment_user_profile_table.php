<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishment_user_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('user_profile_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamps();

            // Explicit name: the auto-generated one exceeds MySQL's 64-char identifier limit.
            $table->unique(['establishment_id', 'user_profile_id'], 'est_user_profile_unique');
            $table->index('user_profile_id'); // reverse lookup: establishments of a profile (visibility scope)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_user_profile');
    }
};
