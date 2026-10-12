<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit trail of one reconciliation run that changed something (or was aborted). Append-only. */
class ProgramAlertReconciliation extends Model
{
    use HasGuid;

    public const UPDATED_AT = null;

    protected $fillable = ['program_id', 'user_id', 'trigger', 'counts', 'aborted'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'aborted' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
