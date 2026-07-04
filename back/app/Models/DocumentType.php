<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentType extends Model
{
    use HasGuid;

    protected $fillable = ['guid', 'country_id', 'name', 'validation_regex'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
