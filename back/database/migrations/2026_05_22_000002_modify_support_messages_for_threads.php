<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropForeign(['responded_by']);
            $table->dropColumn(['admin_response', 'responded_at', 'responded_by']);
            $table->timestamp('closed_at')->nullable()->after('status');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropColumn(['closed_at', 'closed_by']);
            $table->text('admin_response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
