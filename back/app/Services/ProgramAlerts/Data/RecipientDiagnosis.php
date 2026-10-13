<?php

namespace App\Services\ProgramAlerts\Data;

/**
 * Read-only view of how a responsible would be reached today. `phone` is the normalized
 * number and must NEVER leave the backend; only `masked` is presentable.
 */
final readonly class RecipientDiagnosis
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const INVALID_NUMBER = 'invalid_number';

    public function __construct(
        public string $whatsappStatus,
        public ?string $masked,
        public ?string $phone,
        public bool $emailFallback,
        public ?string $emailMasked,
        public bool $optedOut,
    ) {}

    /** ok | email_only | missing | invalid_number | opted_out (preview contract `contact_status`). */
    public function contactStatus(): string
    {
        return match (true) {
            $this->optedOut => 'opted_out',
            $this->whatsappStatus === self::INVALID_NUMBER => 'invalid_number',
            $this->whatsappStatus === self::MISSING => $this->emailFallback ? 'email_only' : 'missing',
            default => 'ok',
        };
    }
}
