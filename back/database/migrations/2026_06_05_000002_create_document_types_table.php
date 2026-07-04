<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->foreignId('country_id')
                  ->constrained('countries')
                  ->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('validation_regex', 200);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
