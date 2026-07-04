<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class HealthPlanTemplateActivity extends Pivot
{
    public $incrementing = false;
    public $timestamps   = false;

    protected $casts = [
        'months'     => 'array',
        'sort_order' => 'integer',
    ];
}
