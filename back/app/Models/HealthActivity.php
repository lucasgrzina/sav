<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HealthActivity extends Model
{
    use HasGuid;

    protected $fillable = ['name', 'description'];
    protected $hidden   = ['id'];

    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(
            HealthPlanTemplate::class,
            'health_plan_template_activity',
            'health_activity_id',
            'health_plan_template_id',
        )->withPivot('months')->using(HealthPlanTemplateActivity::class);
    }
}
