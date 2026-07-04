<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageCreatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SupportMessage $message,
    ) {}
}
