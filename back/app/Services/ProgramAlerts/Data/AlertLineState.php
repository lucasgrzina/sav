<?php

namespace App\Services\ProgramAlerts\Data;

use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\AlertRecipient;

/** Derived presentation state of one (alert, person) line of the program "Avisos" tab. */
final readonly class AlertLineState
{
    public const SCHEDULED = 'scheduled';
    public const IN_PROCESS = 'in_process';
    public const SENT = 'sent';
    public const DELIVERED = 'delivered';
    public const READ = 'read';
    public const FAILED = 'failed';
    public const NOT_SENT = 'not_sent';
    public const CANCELLED = 'cancelled';

    public const BUCKET_SCHEDULED = 'scheduled';
    public const BUCKET_SENT = 'sent';
    public const BUCKET_PROBLEM = 'problem';
    public const BUCKET_CANCELLED = 'cancelled';

    /**
     * @param AlertRecipient|null $primary the attempt that explains the state (WhatsApp first)
     */
    public function __construct(
        public string $state,
        public string $bucket,
        public ?FailureCode $failureCode,
        public bool $fallbackUsed,
        public ?AlertRecipient $primary,
    ) {}

    public static function bucketFor(string $state): string
    {
        return match ($state) {
            self::SCHEDULED, self::IN_PROCESS => self::BUCKET_SCHEDULED,
            self::SENT, self::DELIVERED, self::READ => self::BUCKET_SENT,
            self::FAILED, self::NOT_SENT => self::BUCKET_PROBLEM,
            default => self::BUCKET_CANCELLED,
        };
    }
}
