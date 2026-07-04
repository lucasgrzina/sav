<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('token', 64);
            $table->string('code', 6);
            $table->boolean('used')->default(false);
            $table->timestamps();

            $table->unique('user_id');
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
