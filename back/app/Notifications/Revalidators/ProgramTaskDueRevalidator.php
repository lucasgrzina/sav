<?php

namespace App\Notifications\Revalidators;

use App\Notifications\Contracts\AlertRevalidator;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Services\ProgramAlerts\ProgramAlertReconciler;

final class ProgramTaskDueRevalidator implements AlertRevalidator
{
    public function __construct(private readonly ProgramAlertReconciler $reconciler) {}

    public function type(): AlertType
    {
        return AlertType::ProgramTaskDue;
    }

    public function check(Alert $alert, AlertRecipient $recipient): ?FailureCode
    {
        return $this->reconciler->isStillValid($alert, $recipient->user_profile_id)
            ? null
            : FailureCode::NoLongerApplicable;
    }
}
