<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 40)->nullable()->comment('AlertCancelReason enum value');
            $table->char('logical_key', 40)->nullable()
                  ->comment('sha1 of program|type|target|task-alert|scheduled_at; NULL when cancelled or legacy');
            $table->unique('logical_key', 'alerts_logical_key_unique');
        });

        Schema::table('alert_recipients', function (Blueprint $table) {
            $table->string('failure_code', 40)->nullable()
                  ->comment('FailureCode enum value; failure_reason is internal detail');
            $table->string('destination_hint', 120)->nullable()->comment('Masked destination used at send time');
            $table->text('rendered_body')->nullable();
            $table->unsignedSmallInteger('template_version')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            // No FK on purpose: the user may be deleted, this column is audit-only.
            $table->unsignedBigInteger('last_retry_by_user_id')->nullable();
        });

        $this->backfillFailureCode();
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            // The unique index must be dropped before its column (required by SQLite).
            $table->dropUnique('alerts_logical_key_unique');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancel_reason', 'logical_key']);
        });

        Schema::table('alert_recipients', function (Blueprint $table) {
            $table->dropColumn([
                'failure_code', 'destination_hint', 'rendered_body', 'template_version',
                'retry_count', 'last_retry_at', 'last_retry_by_user_id',
            ]);
        });
    }

    /** Deterministic patterns only; anything else stays NULL (the API reports `unknown`). Idempotent. */
    private function backfillFailureCode(): void
    {
        foreach (['opted_out', 'no_longer_applicable', 'duplicate', 'rate_limited', 'quiet_hours'] as $code) {
            DB::table('alert_recipients')
                ->whereNull('failure_code')
                ->where('status', 'suppressed')
                ->where('failure_reason', $code)
                ->update(['failure_code' => $code]);
        }

        foreach (['whatsapp' => 'no_whatsapp_contact', 'email' => 'no_email_contact'] as $channel => $code) {
            DB::table('alert_recipients')
                ->whereNull('failure_code')
                ->where('status', 'failed')
                ->where('channel', $channel)
                ->where('failure_reason', 'like', "El perfil % no tiene un contacto {$channel} habilitado para alertas")
                ->update(['failure_code' => $code]);
        }
    }
};
