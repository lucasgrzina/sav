<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageClosedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SupportMessage $message,
    ) {}
}
