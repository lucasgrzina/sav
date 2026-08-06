<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProgramTarget extends Model
{
    use HasGuid;

    protected $fillable = ['program_id', 'target_date'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['target_date' => 'date'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function animals(): BelongsToMany
    {
        return $this->belongsToMany(Animal::class, 'program_target_animal');
    }
}
