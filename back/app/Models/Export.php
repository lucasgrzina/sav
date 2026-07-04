<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Export extends Model
{
    use HasGuid;

    protected $fillable = [
        'guid', 'user_id', 'type', 'format', 'status',
        'file_path', 'file_name', 'filters', 'columns',
        'error_message', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'type'       => ExportType::class,
            'format'     => ExportFormat::class,
            'status'     => ExportStatus::class,
            'filters'    => 'array',
            'columns'    => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::COMPLETED
            && ! $this->isExpired()
            && $this->file_path !== null;
    }
}
