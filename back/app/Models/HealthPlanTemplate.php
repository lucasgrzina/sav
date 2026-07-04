<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HealthPlanTemplate extends Model
{
    use HasGuid;

    protected $fillable = ['name', 'health_plan_category_id'];
    protected $hidden   = ['id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(HealthPlanCategory::class, 'health_plan_category_id');
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(
            HealthActivity::class,
            'health_plan_template_activity',
            'health_plan_template_id',
            'health_activity_id',
        )->withPivot('months', 'sort_order')
         ->orderByPivot('sort_order')
         ->using(HealthPlanTemplateActivity::class);
    }
}
