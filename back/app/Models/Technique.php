<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technique extends Model
{
    use HasGuid;

    protected $fillable = [
        'name',
        'target_date_name',
        'type',
        'parent_id',
        'protocols_name',
    ];

    protected $hidden = ['id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Technique::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Technique::class, 'parent_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }

    public function protocols(): HasMany
    {
        return $this->hasMany(Protocol::class);
    }
}
