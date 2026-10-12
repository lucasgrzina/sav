-- =====================================================================
-- SAV - Fase 3 / PR-5: audit table for the program alert reconciler
-- Date: 2026-10-13
-- Target: PRODUCTION MySQL (run from phpMyAdmin, SQL tab, database selected)
-- Mirrors migration: 2026_10_13_000002_create_program_alert_reconciliations_table
-- =====================================================================
--
-- WHY
--   Production has no SSH and the deploy does NOT run `php artisan migrate`, so the new
--   table must exist BEFORE the PR-5 code is deployed (the reconciler writes one audit row
--   per run that changed something, or per aborted run). Expand/contract: the table is new
--   and nothing in the OLD code references it, so a code rollback is safe.
--
-- WHAT THIS SCRIPT DOES
--   STEP 2A  CREATE TABLE IF NOT EXISTS program_alert_reconciliations (+ FK to programs,
--            ON DELETE CASCADE, + index (program_id, created_at)).
--   STEP 2B  Registers the migration in `migrations` so that a future `migrate` is a no-op.
--
-- NOTES
--   * IDEMPOTENT: CREATE TABLE IF NOT EXISTS and a NOT EXISTS guard on the `migrations` row.
--     Re-running changes nothing.
--   * No existing table or row is modified (only one INSERT into `migrations`).
--   * MySQL DDL commits implicitly: run 2A and 2B as separate statements ("Go" each one).
--   * PREREQUISITE: tables `programs` (InnoDB) and `migrations` must exist (they do).
--
-- BEFORE RUNNING
--   >>> EXPORT / BACKUP THE DATABASE FIRST (phpMyAdmin > Export > Quick, SQL). <<<
--   Order in production: (1) run script 01, (2) run this script, (3) deploy the code.
-- =====================================================================


-- =====================================================================
-- SECTION 1 - DIAGNOSTIC (read-only SELECTs)
-- =====================================================================

-- 1A. Does the table already exist? Expected on the first run: 0 rows.
SELECT TABLE_NAME
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_alert_reconciliations';

-- 1B. Is the migration already registered? Expected on the first run: 0 rows.
SELECT * FROM migrations
 WHERE migration = '2026_10_13_000002_create_program_alert_reconciliations_table';

-- 1C. The FK target is InnoDB. Expected: ENGINE = InnoDB.
SELECT TABLE_NAME, ENGINE
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs';


-- =====================================================================
-- SECTION 2 - SCHEMA CHANGE
-- Run each statement (each "-- STEP" block) separately.
-- =====================================================================

-- ---------------------------------------------------------------------
-- STEP 2A - audit table
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `program_alert_reconciliations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `guid` char(36) NOT NULL,
  `program_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `trigger` varchar(40) NOT NULL COMMENT 'program_created|program_updated|program_cancelled|managers_unlinked|protocol_updated|manual_rebuild|nightly|command',
  `counts` json NOT NULL COMMENT 'kept, updated, created, cancelled by reason, untouched_dispatched, skipped_past, adopted, recipients',
  `aborted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `program_alert_reconciliations_guid_unique` (`guid`),
  KEY `par_program_created_idx` (`program_id`,`created_at`),
  CONSTRAINT `program_alert_reconciliations_program_id_foreign` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- STEP 2B - register the migration (so a future `php artisan migrate` skips it)
-- Idempotent: inserts nothing if the row already exists.
-- ---------------------------------------------------------------------

INSERT INTO migrations (migration, batch)
SELECT '2026_10_13_000002_create_program_alert_reconciliations_table', b.next_batch
  FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM migrations) AS b
 WHERE NOT EXISTS (SELECT 1 FROM migrations
                    WHERE migration = '2026_10_13_000002_create_program_alert_reconciliations_table');


-- =====================================================================
-- SECTION 3 - VERIFICATION (read-only)
-- =====================================================================

-- 3A. Columns. Expected: 8 rows (id, guid, program_id, user_id, trigger, counts, aborted, created_at).
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_alert_reconciliations'
 ORDER BY ORDINAL_POSITION;

-- 3B. Indexes and FK. Expected: PRIMARY, program_alert_reconciliations_guid_unique, par_program_created_idx
--     and the FK program_alert_reconciliations_program_id_foreign (DELETE_RULE = CASCADE).
SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_alert_reconciliations'
 ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT CONSTRAINT_NAME, DELETE_RULE
  FROM information_schema.REFERENTIAL_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'program_alert_reconciliations';

-- 3C. Migration registered. Expected: 1 row.
SELECT * FROM migrations
 WHERE migration = '2026_10_13_000002_create_program_alert_reconciliations_table';


-- =====================================================================
-- ROLLBACK (manual, only if the code was NOT deployed yet or was rolled back)
-- Uncomment and run each statement separately. Drops the audit trail.
-- =====================================================================
-- DROP TABLE IF EXISTS `program_alert_reconciliations`;
-- DELETE FROM migrations
--  WHERE migration = '2026_10_13_000002_create_program_alert_reconciliations_table';
