<?php

namespace App\Services\ProgramAlerts\Data;

/** Warning codes of the program alert contract. The copy lives in the frontend, keyed by code. */
enum WarningCode: string
{
    case AlertRoleWithoutRecipient = 'ALERT_ROLE_WITHOUT_RECIPIENT';
    case RecipientWithoutWhatsapp = 'RECIPIENT_WITHOUT_WHATSAPP';
    case RecipientInvalidNumber = 'RECIPIENT_INVALID_NUMBER';
    case RecipientOptedOut = 'RECIPIENT_OPTED_OUT';
    case AlertInPast = 'ALERT_IN_PAST';
    case NoManagersSelected = 'NO_MANAGERS_SELECTED';
    case RecipientEmailOnly = 'RECIPIENT_EMAIL_ONLY';
    case DuplicateTargetDate = 'DUPLICATE_TARGET_DATE';
    case HighVolumeForRecipient = 'HIGH_VOLUME_FOR_RECIPIENT';
    case TaskWithoutAlerts = 'TASK_WITHOUT_ALERTS';
}
