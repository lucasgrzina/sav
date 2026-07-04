<?php

namespace App\Events;

use App\Models\SupportMessage;
use App\Models\SupportMessageReply;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageRepliedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SupportMessage      $message,
        public readonly SupportMessageReply $reply,
    ) {}
}
