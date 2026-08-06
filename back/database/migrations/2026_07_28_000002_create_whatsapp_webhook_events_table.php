<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw inbound delivery-status webhooks. Persisting the payload verbatim before
     * processing gives three things: deduplication (unique idempotency_key, since providers
     * retry until they get a 200), an audit trail when a delivery status looks wrong, and a
     * record of which payload contract each row arrived under.
     */
    public function up(): void
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('idempotency_key')->unique();
            $table->string('event_type');
            $table->string('provider_message_id')->nullable()->index();
            $table->json('payload');
            // Kapso versions its payload shape (X-Webhook-Payload-Version, currently v2).
            $table->string('payload_version')->nullable();
            $table->timestamp('processed_at')->nullable();
            // Why processing changed nothing (out-of-order event, unknown message, opt-out).
            $table->string('outcome')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
    }
};
