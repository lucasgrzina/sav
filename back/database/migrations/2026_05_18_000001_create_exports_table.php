<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('guid')->unique()->index();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->string('type', 50)->comment('ExportType enum value: users');
            $table->string('format', 10)->comment('ExportFormat enum value: xlsx, csv, txt, pdf');
            $table->string('status', 20)->default('pending')->comment('ExportStatus enum value');
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
