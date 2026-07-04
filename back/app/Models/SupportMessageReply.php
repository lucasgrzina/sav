<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessageReply extends Model
{
    use HasGuid;

    protected $fillable = [
        'support_message_id',
        'user_id',
        'body',
    ];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'guid';
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
