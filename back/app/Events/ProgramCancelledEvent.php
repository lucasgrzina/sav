<?php

namespace App\Events;

use App\Models\Program;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProgramCancelledEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Program $program,
    ) {}
}
