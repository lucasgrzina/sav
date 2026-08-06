<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Protocol extends Model
{
    use HasGuid, SoftDeletes;

    protected $fillable = [
        'technique_id', 'country_id', 'vet_id',
        'created_by_type', 'created_by_id', 'name', 'color',
    ];

    protected $hidden = ['id'];

    public function technique(): BelongsTo
    {
        return $this->belongsTo(Technique::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProtocolTask::class)->orderBy('sort_order');
    }
}
