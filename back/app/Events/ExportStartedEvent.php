<?php

namespace App\Events;

use App\Models\Export;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExportStartedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Export $export,
    ) {}
}
