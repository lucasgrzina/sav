<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['tax_id']);
            $table->unique(['country_id', 'tax_id'], 'clients_country_tax_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique('clients_country_tax_id_unique');
            $table->index('tax_id');
        });
    }
};
