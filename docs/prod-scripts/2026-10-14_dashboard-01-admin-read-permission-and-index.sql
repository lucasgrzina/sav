-- =====================================================================
-- SAV - Admin dashboard: `dashboard.admin.read` permission + alert_recipients index
-- Date: 2026-10-14
-- Target: PRODUCTION MySQL (run from phpMyAdmin, SQL tab, database selected)
-- Mirrors migrations:
--   2026_10_14_000001_add_dashboard_admin_read_permission
--   2026_10_14_000002_add_status_sent_at_index_to_alert_recipients_table
-- =====================================================================
--
-- WHY
--   Production has no SSH and the deploy does NOT run `php artisan migrate`. The new endpoint
--   GET /v1/admin/dashboard is guarded by `can:dashboard.admin.read`, so the permission must
--   exist and be assigned BEFORE the code is deployed (otherwise even admins get 403).
--   Expand/contract: an unused permission and an extra index are harmless for the old code.
--
-- WHAT THIS SCRIPT DOES
--   STEP 2A  Creates the permission (guard `web`, guid = UUID()) if it does not exist.
--   STEP 2B  Grants it to the roles `super-admin` and `admin` ONLY (never `operador`).
--   STEP 2C  Creates index (status, sent_at) on `alert_recipients` if it does not exist.
--   STEP 2D  Registers both migrations in `migrations` so a future `migrate` is a no-op.
--
-- NOTES
--   * IDEMPOTENT: inserts are guarded by NOT EXISTS; the index is guarded by information_schema.
--   * Only INSERTs + one CREATE INDEX: no existing row is modified or deleted.
--   * The index build on `alert_recipients` is an online InnoDB operation, but run it in a
--     low-traffic window if the table is large.
--   * SPATIE CACHE: permissions are cached (up to 24 h). After this script, clear the cache
--     (SECTION 4), otherwise the new permission is not seen until the cache expires.
--
-- BEFORE RUNNING
--   >>> EXPORT / BACKUP THE DATABASE FIRST (phpMyAdmin > Export > Quick, SQL). <<<
--   Order in production: this script first, then deploy the code.
-- =====================================================================


-- =====================================================================
-- SECTION 1 - DIAGNOSTIC (read-only SELECTs)
-- =====================================================================

-- 1A. Does the permission already exist? Expected on the first run: 0 rows.
SELECT id, guid, name, guard_name FROM permissions
 WHERE name = 'dashboard.admin.read' AND guard_name = 'web';

-- 1B. Target roles. Expected: admin, super-admin (2 rows). If one is missing, it is skipped.
SELECT id, name FROM roles
 WHERE name IN ('super-admin', 'admin') AND guard_name = 'web';

-- 1C. Does the index already exist? Expected on the first run: 0 rows.
SELECT index_name, column_name, seq_in_index
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name = 'alert_recipients'
   AND index_name = 'alert_recipients_status_sent_at_index';

-- 1D. Are the migrations already registered? Expected on the first run: 0 rows.
SELECT * FROM migrations
 WHERE migration IN ('2026_10_14_000001_add_dashboard_admin_read_permission',
                     '2026_10_14_000002_add_status_sent_at_index_to_alert_recipients_table');


-- =====================================================================
-- SECTION 2 - DATA CHANGE
-- Run each "-- STEP" block separately.
-- =====================================================================

-- ---------------------------------------------------------------------
-- STEP 2A - the permission
-- ---------------------------------------------------------------------

INSERT INTO permissions (guid, name, guard_name, created_at, updated_at)
SELECT UUID(), 'dashboard.admin.read', 'web', NOW(), NOW()
 WHERE NOT EXISTS (SELECT 1 FROM permissions
                    WHERE name = 'dashboard.admin.read' AND guard_name = 'web');

-- ---------------------------------------------------------------------
-- STEP 2B - grant it to `super-admin` and `admin` only
-- ---------------------------------------------------------------------

INSERT INTO role_has_permissions (permission_id, role_id)
SELECT np.id, r.id
  FROM permissions np
  JOIN roles r ON r.name IN ('super-admin', 'admin') AND r.guard_name = 'web'
 WHERE np.name = 'dashboard.admin.read' AND np.guard_name = 'web'
   AND NOT EXISTS (SELECT 1 FROM role_has_permissions x
                    WHERE x.permission_id = np.id AND x.role_id = r.id);

-- ---------------------------------------------------------------------
-- STEP 2C - index (status, sent_at) on alert_recipients (idempotent)
-- ---------------------------------------------------------------------

SET @sav_idx_exists = (SELECT COUNT(*) FROM information_schema.statistics
                        WHERE table_schema = DATABASE()
                          AND table_name = 'alert_recipients'
                          AND index_name = 'alert_recipients_status_sent_at_index');

SET @sav_idx_sql = IF(@sav_idx_exists = 0,
    'CREATE INDEX alert_recipients_status_sent_at_index ON alert_recipients (status, sent_at)',
    'SELECT ''index already exists, nothing to do'' AS info');

PREPARE sav_idx_stmt FROM @sav_idx_sql;
EXECUTE sav_idx_stmt;
DEALLOCATE PREPARE sav_idx_stmt;

-- ---------------------------------------------------------------------
-- STEP 2D - register the migrations (idempotent)
-- ---------------------------------------------------------------------

INSERT INTO migrations (migration, batch)
SELECT '2026_10_14_000001_add_dashboard_admin_read_permission', b.next_batch
  FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM migrations) AS b
 WHERE NOT EXISTS (SELECT 1 FROM migrations
                    WHERE migration = '2026_10_14_000001_add_dashboard_admin_read_permission');

INSERT INTO migrations (migration, batch)
SELECT '2026_10_14_000002_add_status_sent_at_index_to_alert_recipients_table', b.next_batch
  FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM migrations) AS b
 WHERE NOT EXISTS (SELECT 1 FROM migrations
                    WHERE migration = '2026_10_14_000002_add_status_sent_at_index_to_alert_recipients_table');


-- =====================================================================
-- SECTION 3 - VERIFICATION (read-only)
-- =====================================================================

-- 3A. Permission and roles. Expected: exactly admin and super-admin (and NOT operador).
SELECT r.name AS role, p.name AS permission
  FROM role_has_permissions rhp
  JOIN roles r ON r.id = rhp.role_id
  JOIN permissions p ON p.id = rhp.permission_id
 WHERE p.name = 'dashboard.admin.read'
 ORDER BY r.name;

-- 3B. Index. Expected: 2 rows (seq 1 = status, seq 2 = sent_at).
SELECT index_name, column_name, seq_in_index
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name = 'alert_recipients'
   AND index_name = 'alert_recipients_status_sent_at_index'
 ORDER BY seq_in_index;

-- 3C. Migrations registered. Expected: 2 rows.
SELECT * FROM migrations
 WHERE migration IN ('2026_10_14_000001_add_dashboard_admin_read_permission',
                     '2026_10_14_000002_add_status_sent_at_index_to_alert_recipients_table');


-- =====================================================================
-- SECTION 4 - SPATIE PERMISSION CACHE (required, otherwise up to 24 h of 403)
-- =====================================================================
-- Depends on CACHE_STORE in production:
--   * `database`: first look at the keys (the prefix depends on CACHE_PREFIX / APP_NAME):
--         SELECT `key` FROM cache WHERE `key` LIKE '%spatie.permission.cache%';
--     then delete them:
--         DELETE FROM cache WHERE `key` LIKE '%spatie.permission.cache%';
--   * `file`: delete the contents of storage/framework/cache/data through FTP / hosting panel.
--   * `redis`: flush the key `spatie.permission.cache` from the panel.
--   * Or, if the remote artisan runner is enabled: POST /_ops/artisan/permission-cache-reset
-- Run the statement(s) that apply and verify the new permission with 3A.


-- =====================================================================
-- ROLLBACK (manual, only if the code was NOT deployed yet or was rolled back)
-- Uncomment and run each statement separately.
-- =====================================================================
-- DELETE FROM role_has_permissions
--  WHERE permission_id = (SELECT id FROM permissions WHERE name = 'dashboard.admin.read' AND guard_name = 'web');
-- DELETE FROM model_has_permissions
--  WHERE permission_id = (SELECT id FROM permissions WHERE name = 'dashboard.admin.read' AND guard_name = 'web');
-- DELETE FROM permissions WHERE name = 'dashboard.admin.read' AND guard_name = 'web';
-- DROP INDEX alert_recipients_status_sent_at_index ON alert_recipients;
-- DELETE FROM migrations
--  WHERE migration IN ('2026_10_14_000001_add_dashboard_admin_read_permission',
--                      '2026_10_14_000002_add_status_sent_at_index_to_alert_recipients_table');
