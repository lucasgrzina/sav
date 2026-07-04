<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['code', 'value', 'type', 'description'];

    public function getParsedValueAttribute(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($this->value, true),
            default   => $this->value,
        };
    }
}
