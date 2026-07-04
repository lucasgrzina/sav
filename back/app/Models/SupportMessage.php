<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportMessage extends Model
{
    use HasGuid;

    protected $fillable = [
        'user_id',
        'subject',
        'body',
        'status',
        'priority',
        'category',
        'closed_at',
        'closed_by',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'guid';
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportMessageReply::class, 'support_message_id')->orderBy('created_at');
    }
}
