<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Animal extends Model
{
    use HasGuid;

    protected $fillable = ['client_id', 'establishment_id', 'rp', 'name', 'type'];

    protected $hidden = ['id'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function programTargets(): BelongsToMany
    {
        return $this->belongsToMany(ProgramTarget::class, 'program_target_animal');
    }
}
