<?php

namespace App\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Internal audit row for one inbound delivery-status webhook. Deliberately without HasGuid:
 * it is never exposed through the API, it exists for deduplication and diagnosis.
 */
class WhatsappWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'idempotency_key',
        'event_type',
        'provider_message_id',
        'payload',
        'payload_version',
        'processed_at',
        'outcome',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
