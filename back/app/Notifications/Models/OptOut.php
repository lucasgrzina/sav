<?php

namespace App\Notifications\Models;

use App\Notifications\Enums\Channel;
use App\Notifications\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;

class OptOut extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['phone', 'channel'];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
        ];
    }

    protected function setPhoneAttribute(string $value): void
    {
        $this->attributes['phone'] = PhoneNumber::normalize($value);
    }
}
