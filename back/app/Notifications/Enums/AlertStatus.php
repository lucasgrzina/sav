<?php

namespace App\Notifications\Enums;

enum AlertStatus: string
{
    case Pending = 'pending';
    case Dispatched = 'dispatched';
    case Cancelled = 'cancelled';
}
