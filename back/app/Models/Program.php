<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    use HasGuid;

    protected $fillable = [
        'vet_id', 'client_id', 'establishment_id', 'technique_id', 'protocol_id', 'comments', 'cancelled_at',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['cancelled_at' => 'datetime'];
    }

    public function vet(): BelongsTo
    {
        return $this->belongsTo(Vet::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function technique(): BelongsTo
    {
        return $this->belongsTo(Technique::class);
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ProgramTarget::class)->orderBy('target_date');
    }

    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'program_manager');
    }

    protected function editable(): Attribute
    {
        return Attribute::get(fn () => $this->cancelled_at === null);
    }
}
