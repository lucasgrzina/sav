<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthPlanCategory extends Model
{
    use HasGuid;

    protected $fillable = ['name', 'description'];
    protected $hidden   = ['id'];

    public function templates(): HasMany
    {
        return $this->hasMany(HealthPlanTemplate::class);
    }
}
