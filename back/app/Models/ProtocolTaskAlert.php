<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProtocolTaskAlert extends Model
{
    use HasGuid, SoftDeletes;

    protected $fillable = ['protocol_task_id', 'offset_days', 'time_of_day', 'time', 'roles', 'message', 'require_confirmation', 'sort_order'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['roles' => 'array', 'require_confirmation' => 'boolean'];
    }

    public function protocolTask(): BelongsTo
    {
        return $this->belongsTo(ProtocolTask::class);
    }
}
