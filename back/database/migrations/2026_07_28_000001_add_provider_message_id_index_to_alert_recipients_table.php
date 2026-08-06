<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * provider_message_id is the only correlation key from an inbound delivery-status
     * webhook back to its AlertRecipient, and a single message produces up to three
     * events (sent, delivered, read). Without this index every event full-scans the table.
     */
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table) {
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
        });
    }
};
