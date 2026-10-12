<?php

namespace App\Services\ProgramAlerts\Data;

use App\Models\UserProfile;
use App\Notifications\Enums\AlertCancelReason;
use App\Notifications\Models\Alert;

/**
 * What a reconciliation WOULD do. Built without side effects by ProgramAlertReconciler::plan()
 * and executed by apply(). A second plan() right after apply() must be empty (idempotency).
 */
final class ReconcilePlan
{
    /** @var list<array{alert: Alert, item: ProjectedAlert}> legacy alerts that get a logical key (+ payload v2 when pending) */
    public array $adopt = [];

    /** @var list<Alert> pending alerts that already match; nothing to do */
    public array $keep = [];

    /** @var list<array{alert: Alert, item: ProjectedAlert}> pending alerts whose payload changed */
    public array $update = [];

    /** @var list<ProjectedAlert> */
    public array $create = [];

    /** @var list<array{alert: Alert, reason: AlertCancelReason}> */
    public array $cancel = [];

    /** @var list<array{alert: Alert, add: list<UserProfile>, reactivate: list<UserProfile>, suppress: list<int>}> */
    public array $recipientChanges = [];

    /** @var list<Alert> already dispatched: never touched */
    public array $untouchedDispatched = [];

    /** @var list<ProjectedAlert> past items with no existing alert: not created */
    public array $skippedPast = [];

    /** @var int pending task_due alerts that existed when the plan was built (valve denominator) */
    public int $pendingCount = 0;

    /** True when applying the plan would not change anything (informational buckets do not count). */
    public function isEmpty(): bool
    {
        return $this->adopt === []
            && $this->update === []
            && $this->create === []
            && $this->cancel === []
            && $this->recipientChanges === [];
    }
}
