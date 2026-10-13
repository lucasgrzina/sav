<?php

namespace App\Notifications\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Production applies the schema by hand (docs/prod-scripts) and the deploy does not run
 * `migrate`, so the code can go live before SQL-01 / SQL-02. Without this guard the delivery
 * and reconciliation paths would fail with unknown-column errors halfway through, leaving
 * recipients `pending` on already `dispatched` alerts. Callers check it up front and stop
 * cleanly instead.
 *
 * Only a positive result is memoized (per process, the guard is a container singleton): once the
 * schema is complete it stays complete, while a missing schema is re-checked so a long-lived
 * queue worker recovers by itself after the SQL is applied.
 */
final class AlertsSchemaGuard
{
    /** @var array<string, list<string>> */
    public const REQUIRED_COLUMNS = [
        'alerts' => ['cancelled_at', 'cancel_reason', 'logical_key'],
        'alert_recipients' => [
            'failure_code', 'destination_hint', 'rendered_body', 'template_version',
            'retry_count', 'last_retry_at', 'last_retry_by_user_id',
        ],
    ];

    /** @var list<string> */
    public const REQUIRED_TABLES = ['program_alert_reconciliations'];

    private bool $ready = false;

    /**
     * @param array<string, list<string>> $columns table => columns
     * @param list<string> $tables
     */
    public function __construct(
        private readonly array $columns = self::REQUIRED_COLUMNS,
        private readonly array $tables = self::REQUIRED_TABLES,
    ) {}

    public function isReady(): bool
    {
        if ($this->ready) {
            return true;
        }

        return $this->ready = $this->missing() === [];
    }

    /** @return list<string> e.g. `alerts.logical_key`, `table program_alert_reconciliations` */
    public function missing(): array
    {
        $missing = [];

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = "table {$table}";
            }
        }

        foreach ($this->columns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = "table {$table}";

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        return array_values(array_unique($missing));
    }
}
