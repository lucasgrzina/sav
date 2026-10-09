<?php

namespace App\Models;

use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstablishmentHealthPlanActivity extends Model
{
    use HasGuid;

    /**
     * DU-07 confirmado (Opción A) — vocabulario propio, no depende de ProtocolAlert.roles
     * (que en código real permite 6 valores, ver Riesgos del plan de Fase 1).
     */
    public const CONFIRM_ROLES = ['vet', 'vet-assistant', 'client-owner', 'client-manager'];

    protected $fillable = [
        'establishment_health_plan_id', 'health_activity_id', 'month', 'due_date',
        'sort_order', 'require_confirmation', 'confirmed_at', 'confirmed_by_profile_id',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'due_date'             => 'date',
            'require_confirmation' => 'boolean',
            'confirmed_at'         => 'datetime',
            'month'                => 'integer',
            'sort_order'           => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(EstablishmentHealthPlan::class, 'establishment_health_plan_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(HealthActivity::class, 'health_activity_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'confirmed_by_profile_id');
    }

    protected function status(): Attribute
    {
        return Attribute::get(fn () => $this->confirmed_at === null ? 'pending' : 'confirmed');
    }
}
