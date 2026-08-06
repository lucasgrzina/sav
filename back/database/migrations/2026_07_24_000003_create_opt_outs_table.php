<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->comment('E.164 sin +, normalizado');
            $table->string('channel');
            $table->timestamp('created_at')->nullable();

            $table->unique(['phone', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opt_outs');
    }
};
