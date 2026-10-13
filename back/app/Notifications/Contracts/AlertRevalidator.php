<?php

namespace App\Notifications\Contracts;

use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;

/**
 * Re-checks, right before sending (including queue retries and the email fallback attempt),
 * that an alert that was valid when it was dispatched still applies to this recipient.
 */
interface AlertRevalidator
{
    public function type(): AlertType;

    /** Returns FailureCode::NoLongerApplicable when the send must be suppressed, null when it may proceed. */
    public function check(Alert $alert, AlertRecipient $recipient): ?FailureCode;
}
