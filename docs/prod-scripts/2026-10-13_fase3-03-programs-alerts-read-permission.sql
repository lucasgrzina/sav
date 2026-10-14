-- =====================================================================
-- SAV - Fase 3 / PR-8: `programs.alerts.read` permission (program "Avisos" tab)
-- Date: 2026-10-13
-- Target: PRODUCTION MySQL (run from phpMyAdmin, SQL tab, database selected)
-- Mirrors migration: 2026_10_13_000003_add_programs_alerts_read_permission
-- =====================================================================
--
-- WHY
--   Production has no SSH and the deploy does NOT run `php artisan migrate`. The new endpoint
--   GET /v1/vets/{vet}/programs/{guid}/alerts is guarded by `can:programs.alerts.read`, so the
--   permission must exist and be assigned BEFORE the PR-8 code is deployed (otherwise every
--   request answers 403). Expand/contract: an unused permission is harmless for the old code.
--
-- WHAT THIS SCRIPT DOES
--   STEP 2A  Creates the permission (guard `web`, guid = UUID()) if it does not exist.
--   STEP 2B  Grants it to every role that holds `programs.read`, EXCEPT roles named `client-%`.
--            Expected result: super-admin, vet, vet-administrative, vet-assistant.
--   STEP 2C  Registers the migration in `migrations` so a future `migrate` is a no-op.
--
-- NOTES
--   * IDEMPOTENT: every INSERT is guarded by NOT EXISTS. Re-running changes nothing.
--   * Only INSERTs: no existing row is modified or deleted.
--   * SPATIE CACHE: permissions are cached (up to 24 h). After this script, clear the cache
--     (SECTION 4), otherwise the new permission is not seen until the cache expires.
--
-- BEFORE RUNNING
--   >>> EXPORT / BACKUP THE DATABASE FIRST (phpMyAdmin > Export > Quick, SQL). <<<
--   Order in production: SQL scripts 01, 02, 03 first, then deploy the code.
-- =====================================================================


-- =====================================================================
-- SECTION 1 - DIAGNOSTIC (read-only SELECTs)
-- =====================================================================

-- 1A. Does the permission already exist? Expected on the first run: 0 rows.
SELECT id, guid, name, guard_name FROM permissions
 WHERE name = 'programs.alerts.read' AND guard_name = 'web';

-- 1B. Roles that would receive it (hold `programs.read`, not `client-%`).
--     Expected: super-admin, vet, vet-administrative, vet-assistant.
SELECT r.id, r.name
  FROM roles r
  JOIN role_has_permissions rhp ON rhp.role_id = r.id
  JOIN permissions p ON p.id = rhp.permission_id
 WHERE p.name = 'programs.read' AND p.guard_name = 'web'
   AND r.name NOT LIKE 'client-%'
 ORDER BY r.name;

-- 1C. Is the migration already registered? Expected on the first run: 0 rows.
SELECT * FROM migrations WHERE migration = '2026_10_13_000003_add_programs_alerts_read_permission';


-- =====================================================================
-- SECTION 2 - DATA CHANGE
-- Run each "-- STEP" block separately.
-- =====================================================================

-- ---------------------------------------------------------------------
-- STEP 2A - the permission
-- ---------------------------------------------------------------------

INSERT INTO permissions (guid, name, guard_name, created_at, updated_at)
SELECT UUID(), 'programs.alerts.read', 'web', NOW(), NOW()
 WHERE NOT EXISTS (SELECT 1 FROM permissions
                    WHERE name = 'programs.alerts.read' AND guard_name = 'web');

-- ---------------------------------------------------------------------
-- STEP 2B - grant it to the roles that hold `programs.read` (never `client-%`)
-- ---------------------------------------------------------------------

INSERT INTO role_has_permissions (permission_id, role_id)
SELECT np.id, src.role_id
  FROM permissions np
  JOIN (SELECT DISTINCT rhp.role_id
          FROM role_has_permissions rhp
          JOIN permissions sp ON sp.id = rhp.permission_id
          JOIN roles r ON r.id = rhp.role_id
         WHERE sp.name = 'programs.read' AND sp.guard_name = 'web'
           AND r.name NOT LIKE 'client-%') src
 WHERE np.name = 'programs.alerts.read' AND np.guard_name = 'web'
   AND NOT EXISTS (SELECT 1 FROM role_has_permissions x
                    WHERE x.permission_id = np.id AND x.role_id = src.role_id);

-- ---------------------------------------------------------------------
-- STEP 2C - register the migration (idempotent)
-- ---------------------------------------------------------------------

INSERT INTO migrations (migration, batch)
SELECT '2026_10_13_000003_add_programs_alerts_read_permission', b.next_batch
  FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM migrations) AS b
 WHERE NOT EXISTS (SELECT 1 FROM migrations
                    WHERE migration = '2026_10_13_000003_add_programs_alerts_read_permission');


-- =====================================================================
-- SECTION 3 - VERIFICATION (read-only)
-- =====================================================================

-- 3A. Permission and roles. Expected: exactly super-admin, vet, vet-administrative, vet-assistant
--     (and no `client-*` role).
SELECT r.name AS role, p.name AS permission
  FROM role_has_permissions rhp
  JOIN roles r ON r.id = rhp.role_id
  JOIN permissions p ON p.id = rhp.permission_id
 WHERE p.name = 'programs.alerts.read'
 ORDER BY r.name;

-- 3B. Migration registered. Expected: 1 row.
SELECT * FROM migrations WHERE migration = '2026_10_13_000003_add_programs_alerts_read_permission';


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
-- Run the statement(s) that apply and verify the new permission with 3A.


-- =====================================================================
-- ROLLBACK (manual, only if the code was NOT deployed yet or was rolled back)
-- Uncomment and run each statement separately.
-- =====================================================================
-- DELETE FROM role_has_permissions
--  WHERE permission_id = (SELECT id FROM permissions WHERE name = 'programs.alerts.read' AND guard_name = 'web');
-- DELETE FROM model_has_permissions
--  WHERE permission_id = (SELECT id FROM permissions WHERE name = 'programs.alerts.read' AND guard_name = 'web');
-- DELETE FROM permissions WHERE name = 'programs.alerts.read' AND guard_name = 'web';
-- DELETE FROM migrations WHERE migration = '2026_10_13_000003_add_programs_alerts_read_permission';
