<?php

namespace App\Notifications\Enums;

enum AlertCancelReason: string
{
    case Rescheduled = 'rescheduled';
    case ProtocolChanged = 'protocol_changed';
    case RecipientRemoved = 'recipient_removed';
    case GroupRemoved = 'group_removed';
    case ProgramCancelled = 'program_cancelled';
    case Superseded = 'superseded';
}
