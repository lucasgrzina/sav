<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;

class Tutorial extends Model
{
    use HasGuid;

    protected $fillable = [
        'title',
        'description',
        'source',
        'code',
        'order',
    ];

    protected $hidden = ['id'];
}
