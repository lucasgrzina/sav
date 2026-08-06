<?php

namespace App\Notifications\Data;

enum SuppressionReason: string
{
    case OptedOut = 'opted_out';
    case QuietHours = 'quiet_hours';
    case Duplicate = 'duplicate';
    case RateLimited = 'rate_limited';
}
