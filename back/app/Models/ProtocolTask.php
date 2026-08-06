<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProtocolTask extends Model
{
    use HasGuid, SoftDeletes;

    protected $fillable = ['protocol_id', 'description', 'days_offset', 'time_of_day', 'time', 'important', 'sort_order'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['important' => 'boolean'];
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(ProtocolTaskAlert::class)->orderBy('sort_order');
    }
}
