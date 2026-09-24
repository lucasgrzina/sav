<?php

namespace App\Events;

use App\Models\EstablishmentHealthPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstablishmentHealthPlanInstantiatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly EstablishmentHealthPlan $plan,
    ) {}
}
