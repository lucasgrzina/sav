<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->foreignId('province_id')->nullable()->after('state')
                  ->constrained('provinces')
                  ->nullOnDelete()
                  ->comment('Structured province; kept in sync with the legacy text column state');
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('province_id');
        });
    }
};
