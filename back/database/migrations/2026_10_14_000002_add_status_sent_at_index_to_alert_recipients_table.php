<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the admin dashboard delivery stats (filter by status + sent_at window).
     */
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table) {
            $table->index(['status', 'sent_at'], 'alert_recipients_status_sent_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table) {
            $table->dropIndex('alert_recipients_status_sent_at_index');
        });
    }
};
