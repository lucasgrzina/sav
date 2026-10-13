-- =====================================================================
-- SAV - Fase 3 / PR-1: delivery traceability schema for alerts and alert_recipients
-- Date: 2026-10-13
-- Target: PRODUCTION MySQL (run from phpMyAdmin, SQL tab, database selected)
-- Mirrors migration: 2026_10_13_000001_add_traceability_columns_to_alerts_and_alert_recipients
-- =====================================================================
--
-- WHY
--   Production has no SSH and the deploy does NOT run `php artisan migrate`, so the
--   schema change must be applied by hand BEFORE deploying the PR-1 code (expand/contract:
--   everything is additive and nullable, so the OLD code keeps working with the new schema
--   and a code rollback is safe).
--
-- WHAT THIS SCRIPT DOES
--   STEP 2A  alerts: + cancelled_at, + cancel_reason, + logical_key (+ unique index).
--   STEP 2B  alert_recipients: + failure_code, + destination_hint, + rendered_body,
--            + template_version, + retry_count (NOT NULL DEFAULT 0), + last_retry_at,
--            + last_retry_by_user_id (no FK on purpose: audit-only).
--   STEP 2C  Backfill of failure_code, ONLY deterministic patterns (anything else stays NULL
--            and the API reports it as `unknown`).
--   STEP 2D  Registers the migration in `migrations` so that a future `migrate` is a no-op.
--
-- NOTES
--   * IDEMPOTENT: every ADD COLUMN / ADD UNIQUE is guarded by an information_schema check
--     (MySQL has no ADD COLUMN IF NOT EXISTS). Re-running changes nothing.
--   * MySQL DDL commits implicitly: there is no useful transaction around 2A/2B. Run the
--     statements one at a time (select a block, press "Go"). Each block is independent.
--   * No existing data is modified except failure_code in 2C (only rows where it is NULL).
--   * `alerts.status` stays a plain string column; the new value `cancelled` needs no DDL.
--
-- BEFORE RUNNING
--   >>> EXPORT / BACKUP THE DATABASE FIRST (phpMyAdmin > Export > Quick, SQL). <<<
--   Order in production: (1) run this script, (2) run fase3-02 (reconciliations table),
--   (3) deploy the code. Verify with `php artisan alerts:schema-check` (exit code 1 = pending SQL).
--
-- RUNBOOK - code deployed BEFORE this script (wrong order)
--   The code protects itself: `alerts:dispatch-due` refuses to run (alerts stay `pending`),
--   DeliverAlertJob releases without touching the recipient, and the reconciler skips. Jobs that
--   exhausted their retries in that window leave the recipient `pending` on an alert that is
--   already `dispatched`. After applying the SQL, re-queue them (run once the queue is drained,
--   otherwise a job still waiting for its retry could be delivered twice):
--
--     -- 1) what is stuck
--     SELECT ar.id, ar.alert_id, ar.channel, a.scheduled_at
--       FROM alert_recipients ar JOIN alerts a ON a.id = ar.alert_id
--      WHERE ar.status = 'pending' AND a.status = 'dispatched';
--
--     -- 2) give those alerts back to the dispatcher; it only fans out the recipients that are
--     --    still `pending`, so already-processed recipients are never sent twice
--     UPDATE alerts a SET a.status = 'pending'
--      WHERE a.status = 'dispatched'
--        AND EXISTS (SELECT 1 FROM alert_recipients ar WHERE ar.alert_id = a.id AND ar.status = 'pending');
-- =====================================================================


-- =====================================================================
-- SECTION 1 - DIAGNOSTIC (read-only SELECTs)
-- =====================================================================

-- 1A. Preflight: which of the new columns already exist? Expected on the first run: 0 rows.
--     If some are listed, the guarded statements in 2A/2B simply skip them.
SELECT TABLE_NAME, COLUMN_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = 'alerts'
         AND COLUMN_NAME IN ('cancelled_at', 'cancel_reason', 'logical_key'))
     OR (TABLE_NAME = 'alert_recipients'
         AND COLUMN_NAME IN ('failure_code', 'destination_hint', 'rendered_body',
                             'template_version', 'retry_count', 'last_retry_at',
                             'last_retry_by_user_id')))
 ORDER BY TABLE_NAME, COLUMN_NAME;

-- 1B. Is the migration already registered? Expected on the first run: 0 rows.
SELECT * FROM migrations
 WHERE migration = '2026_10_13_000001_add_traceability_columns_to_alerts_and_alert_recipients';

-- 1C. Table sizes (sanity check before ALTER TABLE; both are small, the ALTERs are quick).
SELECT (SELECT COUNT(*) FROM alerts)           AS alerts_rows,
       (SELECT COUNT(*) FROM alert_recipients) AS alert_recipients_rows;

-- 1D. Rows the backfill (2C) would touch, by pattern. Only meaningful BEFORE 2B, and only
--     once failure_code exists, so this one is a preview based on failure_reason alone.
SELECT status, channel, failure_reason, COUNT(*) AS total
  FROM alert_recipients
 WHERE status IN ('suppressed', 'failed')
   AND failure_reason IS NOT NULL
 GROUP BY status, channel, failure_reason
 ORDER BY total DESC
 LIMIT 50;


-- =====================================================================
-- SECTION 2 - SCHEMA CHANGE
-- Run each statement (each "-- STEP" block) separately.
-- =====================================================================

-- ---------------------------------------------------------------------
-- STEP 2A - alerts
-- ---------------------------------------------------------------------

-- 2A.1 cancelled_at
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts' AND COLUMN_NAME = 'cancelled_at') = 0,
              'ALTER TABLE `alerts` ADD COLUMN `cancelled_at` TIMESTAMP NULL DEFAULT NULL',
              'SELECT ''alerts.cancelled_at already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2A.2 cancel_reason
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts' AND COLUMN_NAME = 'cancel_reason') = 0,
              'ALTER TABLE `alerts` ADD COLUMN `cancel_reason` VARCHAR(40) NULL DEFAULT NULL COMMENT ''AlertCancelReason enum value''',
              'SELECT ''alerts.cancel_reason already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2A.3 logical_key
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts' AND COLUMN_NAME = 'logical_key') = 0,
              'ALTER TABLE `alerts` ADD COLUMN `logical_key` CHAR(40) NULL DEFAULT NULL COMMENT ''sha1 of program|type|target|task-alert|scheduled_at; NULL when cancelled or legacy''',
              'SELECT ''alerts.logical_key already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2A.4 unique index on logical_key (MySQL allows many NULLs in a unique index)
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts' AND INDEX_NAME = 'alerts_logical_key_unique') = 0,
              'ALTER TABLE `alerts` ADD UNIQUE KEY `alerts_logical_key_unique` (`logical_key`)',
              'SELECT ''alerts_logical_key_unique already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- STEP 2B - alert_recipients
-- ---------------------------------------------------------------------

-- 2B.1 failure_code
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_recipients' AND COLUMN_NAME = 'failure_code') = 0,
              'ALTER TABLE `alert_recipients` ADD COLUMN `failure_code` VARCHAR(40) NULL DEFAULT NULL COMMENT ''FailureCode enum value; failure_reason is internal detail''',
              'SELECT ''alert_recipients.failure_code already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2B.2 destination_hint
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_recipients' AND COLUMN_NAME = 'destination_hint') = 0,
              'ALTER TABLE `alert_recipients` ADD COLUMN `destination_hint` VARCHAR(120) NULL DEFAULT NULL COMMENT ''Masked destination used at send time''',
              'SELECT ''alert_recipients.destination_hint already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2B.3 rendered_body
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_recipients' AND COLUMN_NAME = 'rendered_body') = 0,
              'ALTER TABLE `alert_recipients` ADD COLUMN `rendered_body` TEXT NULL',
              'SELECT ''alert_recipients.rendered_body already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2B.4 template_version
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_recipients' AND COLUMN_NAME = 'template_version') = 0,
              'ALTER TABLE `alert_recipients` ADD COLUMN `template_version` SMALLINT UNSIGNED NULL DEFAULT NULL',
              'SELECT ''alert_recipients.template_version already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2B.5 retry_count
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_recipients' AND COLUMN_NAME = 'retry_count') = 0,
              'ALTER TABLE `alert_recipients` ADD COLUMN `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0',
              'SELECT ''alert_recipients.retry_count already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2B.6 last_retry_at
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_recipients' AND COLUMN_NAME = 'last_retry_at') = 0,
              'ALTER TABLE `alert_recipients` ADD COLUMN `last_retry_at` TIMESTAMP NULL DEFAULT NULL',
              'SELECT ''alert_recipients.last_retry_at already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2B.7 last_retry_by_user_id (no FK: the user may be deleted; audit-only)
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_recipients' AND COLUMN_NAME = 'last_retry_by_user_id') = 0,
              'ALTER TABLE `alert_recipients` ADD COLUMN `last_retry_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL',
              'SELECT ''alert_recipients.last_retry_by_user_id already exists, skipped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- STEP 2C - failure_code backfill (deterministic patterns only; idempotent)
-- Run 2C.1 .. 2C.3 together as one selection. Re-running updates 0 rows.
-- ---------------------------------------------------------------------

START TRANSACTION;

-- 2C.1 suppressed rows whose failure_reason already is a stable code.
UPDATE alert_recipients
   SET failure_code = failure_reason
 WHERE failure_code IS NULL
   AND status = 'suppressed'
   AND failure_reason IN ('opted_out', 'no_longer_applicable', 'duplicate', 'rate_limited', 'quiet_hours');

-- 2C.2 failed rows: no WhatsApp contact enabled for alerts.
UPDATE alert_recipients
   SET failure_code = 'no_whatsapp_contact'
 WHERE failure_code IS NULL
   AND status = 'failed'
   AND channel = 'whatsapp'
   AND failure_reason LIKE 'El perfil % no tiene un contacto whatsapp habilitado para alertas';

-- 2C.3 failed rows: no email contact enabled for alerts.
UPDATE alert_recipients
   SET failure_code = 'no_email_contact'
 WHERE failure_code IS NULL
   AND status = 'failed'
   AND channel = 'email'
   AND failure_reason LIKE 'El perfil % no tiene un contacto email habilitado para alertas';

-- Check the "rows affected" of the three updates above, then:
COMMIT;
-- (If anything looks wrong, run ROLLBACK; instead of COMMIT; - only valid before COMMIT.)

-- ---------------------------------------------------------------------
-- STEP 2D - register the migration (so a future `php artisan migrate` skips it)
-- Idempotent: inserts nothing if the row already exists.
-- ---------------------------------------------------------------------

INSERT INTO migrations (migration, batch)
SELECT '2026_10_13_000001_add_traceability_columns_to_alerts_and_alert_recipients', b.next_batch
  FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM migrations) AS b
 WHERE NOT EXISTS (SELECT 1 FROM migrations
                    WHERE migration = '2026_10_13_000001_add_traceability_columns_to_alerts_and_alert_recipients');


-- =====================================================================
-- SECTION 3 - VERIFICATION (read-only)
-- =====================================================================

-- 3A. All 10 columns exist. Expected: 10 rows.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = 'alerts'
         AND COLUMN_NAME IN ('cancelled_at', 'cancel_reason', 'logical_key'))
     OR (TABLE_NAME = 'alert_recipients'
         AND COLUMN_NAME IN ('failure_code', 'destination_hint', 'rendered_body',
                             'template_version', 'retry_count', 'last_retry_at',
                             'last_retry_by_user_id')))
 ORDER BY TABLE_NAME, COLUMN_NAME;

-- 3B. Unique index exists. Expected: 1 row, NON_UNIQUE = 0.
SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts' AND INDEX_NAME = 'alerts_logical_key_unique';

-- 3C. Migration registered. Expected: 1 row.
SELECT * FROM migrations
 WHERE migration = '2026_10_13_000001_add_traceability_columns_to_alerts_and_alert_recipients';

-- 3D. failure_code distribution after the backfill (NULL = historical, reported as `unknown`).
SELECT status, failure_code, COUNT(*) AS total
  FROM alert_recipients
 WHERE status IN ('failed', 'suppressed')
 GROUP BY status, failure_code
 ORDER BY status, total DESC;


-- =====================================================================
-- ROLLBACK (manual, only if the code was NOT deployed yet or was rolled back)
-- Uncomment and run each statement separately. Drops the new data (traceability only).
--
-- >>> BACK UP `alert_recipients` (and `alerts`) FIRST: dropping the columns destroys
-- >>> failure_code / destination_hint / rendered_body / template_version / retry audit data.
-- >>> e.g. CREATE TABLE alert_recipients_bak_20261013 AS SELECT * FROM alert_recipients;
--
-- CODE ROLLBACK (preferred, no SQL needed): the columns are additive and nullable, so the old
-- code works with them. To roll the code back: (1) stop the nightly job (comment out / disable
-- `programs:reconcile-alerts` in routes/console.php or the cron), (2) set
-- ALERTS_RECONCILER_ENABLED=false in .env and clear the config cache, (3) deploy the previous
-- code and KEEP the columns. Only run the DROP statements below if the schema itself must go.
-- =====================================================================
-- ALTER TABLE `alerts` DROP INDEX `alerts_logical_key_unique`;
-- ALTER TABLE `alerts` DROP COLUMN `cancelled_at`, DROP COLUMN `cancel_reason`, DROP COLUMN `logical_key`;
-- ALTER TABLE `alert_recipients`
--   DROP COLUMN `failure_code`, DROP COLUMN `destination_hint`, DROP COLUMN `rendered_body`,
--   DROP COLUMN `template_version`, DROP COLUMN `retry_count`, DROP COLUMN `last_retry_at`,
--   DROP COLUMN `last_retry_by_user_id`;
-- DELETE FROM migrations
--  WHERE migration = '2026_10_13_000001_add_traceability_columns_to_alerts_and_alert_recipients';
