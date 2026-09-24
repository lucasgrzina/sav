<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstablishmentHealthPlan extends Model
{
    use HasGuid;

    protected $fillable = [
        'vet_id', 'client_id', 'establishment_id', 'health_plan_template_id',
        'year', 'starts_on', 'ends_on', 'created_by_user_id', 'cancelled_at',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on'    => 'date',
            'ends_on'      => 'date',
            'cancelled_at' => 'datetime',
            'year'         => 'integer',
        ];
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(HealthPlanTemplate::class, 'health_plan_template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(EstablishmentHealthPlanActivity::class)->orderBy('sort_order')->orderBy('month');
    }

    protected function editable(): Attribute
    {
        return Attribute::get(fn () => $this->cancelled_at === null);
    }
}
