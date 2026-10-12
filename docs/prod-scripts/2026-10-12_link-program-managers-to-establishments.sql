-- =====================================================================
-- SAV - Link client-type program managers to their program's establishment
-- Date: 2026-10-12
-- Target: PRODUCTION MySQL (run from phpMyAdmin, SQL tab, database selected)
-- =====================================================================
--
-- PROBLEM
--   Program detail reads managers from `program_manager` (program <-> user_profile),
--   but the program edit form (manager-options) and the client file read the staff
--   linked in `establishment_user_profile` (only profiles with blocked_at IS NULL).
--   The pivot `establishment_user_profile` is EMPTY in production (the backfill
--   migration 2026_09_29_000002 never populated it), so:
--     * the edit form says "this establishment has no linked client staff";
--     * client staff show as "no establishments assigned".
--
-- WHAT THIS SCRIPT DOES
--   STEP 2A (applied): links every client-type manager of every ACTIVE
--           program (cancelled_at IS NULL) to that program's establishment, only
--           when the link is missing, the profile belongs to the SAME client as the
--           establishment, has a client staff role and is NOT blocked.
--   STEP 2B (applied, decided by the business: client staff belong to the whole
--           client): full backfill identical to migration 2026_09_29_000002 (every
--           non-blocked client staff profile -> every establishment of its client).
--           Fixes "no establishments assigned" for ALL client staff, not only
--           program managers. 2B is a superset of 2A; both run in ONE transaction.
--   Vet-type managers are intentionally NOT linked (exempt by business rule).
--   Blocked profiles and profiles of a different client are NOT linked; they are
--   listed in the diagnostic (section 1C) for a human decision.
--
-- NOTES
--   * `establishment_user_profile` has no guid / blocked_at column; only
--     (establishment_id, user_profile_id, created_at, updated_at) are required.
--   * Idempotent: INSERT ... SELECT ... WHERE NOT EXISTS (+ unique key
--     est_user_profile_unique as a safety net). Re-running inserts 0 rows.
--   * Touches ONLY `establishment_user_profile`. No UPDATE/DELETE anywhere.
--
-- BEFORE RUNNING
--   >>> EXPORT / BACKUP THE DATABASE FIRST (phpMyAdmin > Export > Quick, SQL). <<<
--   Run the sections one at a time (select the block and press "Go").
-- =====================================================================


-- =====================================================================
-- SECTION 1 - DIAGNOSTIC (read-only SELECTs)
-- =====================================================================

-- 1A. Active-program managers and whether each one is linked to the program's establishment.
SELECT p.id                                   AS program_id,
       p.establishment_id,
       e.name                                 AS establishment,
       pm.user_profile_id,
       up.authenticatable_type                AS profile_type,
       r.name                                 AS role,
       up.blocked_at,
       (up.authenticatable_type = 'client' AND up.authenticatable_id = e.client_id) AS same_client_as_establishment,
       EXISTS (SELECT 1 FROM establishment_user_profile x
                WHERE x.establishment_id = p.establishment_id
                  AND x.user_profile_id  = up.id)                                   AS is_linked
  FROM program_manager pm
  JOIN programs        p  ON p.id  = pm.program_id
  JOIN establishments  e  ON e.id  = p.establishment_id
  JOIN user_profiles   up ON up.id = pm.user_profile_id
  JOIN roles           r  ON r.id  = up.role_id
 WHERE p.cancelled_at IS NULL
 ORDER BY p.id, pm.user_profile_id;

-- 1B. Summary counts.
SELECT
  (SELECT COUNT(*) FROM programs WHERE cancelled_at IS NULL)                         AS active_programs,
  (SELECT COUNT(*) FROM establishment_user_profile)                                  AS pivot_rows_total,
  (SELECT COUNT(*) FROM user_profiles WHERE authenticatable_type = 'client')         AS client_profiles_total,
  (SELECT COUNT(*) FROM user_profiles up
    WHERE up.authenticatable_type = 'client'
      AND NOT EXISTS (SELECT 1 FROM establishment_user_profile x
                       WHERE x.user_profile_id = up.id))                             AS client_profiles_without_any_establishment,
  (SELECT COUNT(DISTINCT pm.program_id, pm.user_profile_id)
     FROM program_manager pm
     JOIN programs p        ON p.id  = pm.program_id AND p.cancelled_at IS NULL
     JOIN user_profiles up  ON up.id = pm.user_profile_id AND up.authenticatable_type = 'client'
    WHERE NOT EXISTS (SELECT 1 FROM establishment_user_profile x
                       WHERE x.establishment_id = p.establishment_id
                         AND x.user_profile_id  = up.id))                            AS client_manager_rows_unlinked,
  (SELECT COUNT(DISTINCT p.id)
     FROM program_manager pm
     JOIN programs p        ON p.id  = pm.program_id AND p.cancelled_at IS NULL
     JOIN user_profiles up  ON up.id = pm.user_profile_id AND up.authenticatable_type = 'client'
    WHERE NOT EXISTS (SELECT 1 FROM establishment_user_profile x
                       WHERE x.establishment_id = p.establishment_id
                         AND x.user_profile_id  = up.id))                            AS active_programs_affected;

-- 1C. AMBIGUOUS cases NOT handled by this script (needs a human decision).
--     Client-type managers of active programs that are unlinked AND (blocked OR belong
--     to another client OR have a non-client-staff role). Expected: 0 rows.
SELECT p.id AS program_id, p.establishment_id, pm.user_profile_id,
       r.name AS role, up.blocked_at,
       (up.authenticatable_id = e.client_id) AS same_client_as_establishment
  FROM program_manager pm
  JOIN programs       p  ON p.id  = pm.program_id AND p.cancelled_at IS NULL
  JOIN establishments e  ON e.id  = p.establishment_id
  JOIN user_profiles  up ON up.id = pm.user_profile_id AND up.authenticatable_type = 'client'
  JOIN roles          r  ON r.id  = up.role_id
 WHERE NOT EXISTS (SELECT 1 FROM establishment_user_profile x
                    WHERE x.establishment_id = p.establishment_id
                      AND x.user_profile_id  = up.id)
   AND (up.blocked_at IS NOT NULL
        OR up.authenticatable_id <> e.client_id
        OR r.name NOT IN ('client-owner', 'client-manager', 'client-administrative'));

-- 1D. Rows that STEP 2A would insert (preview).
SELECT DISTINCT p.establishment_id, up.id AS user_profile_id, r.name AS role
  FROM program_manager pm
  JOIN programs       p  ON p.id  = pm.program_id AND p.cancelled_at IS NULL
  JOIN establishments e  ON e.id  = p.establishment_id
  JOIN user_profiles  up ON up.id = pm.user_profile_id
                        AND up.authenticatable_type = 'client'
                        AND up.authenticatable_id   = e.client_id
                        AND up.blocked_at IS NULL
  JOIN roles          r  ON r.id  = up.role_id
                        AND r.name IN ('client-owner', 'client-manager', 'client-administrative')
 WHERE NOT EXISTS (SELECT 1 FROM establishment_user_profile x
                    WHERE x.establishment_id = p.establishment_id
                      AND x.user_profile_id  = up.id);


-- =====================================================================
-- SECTION 2 - FIX (writes to establishment_user_profile ONLY)
-- Run STEP 2A and STEP 2B together as a single selection (one transaction).
-- =====================================================================

START TRANSACTION;

-- STEP 2A - link missing client-type program managers (active programs only).
INSERT INTO establishment_user_profile (establishment_id, user_profile_id, created_at, updated_at)
SELECT DISTINCT p.establishment_id, up.id, NOW(), NOW()
  FROM program_manager pm
  JOIN programs       p  ON p.id  = pm.program_id AND p.cancelled_at IS NULL
  JOIN establishments e  ON e.id  = p.establishment_id
  JOIN user_profiles  up ON up.id = pm.user_profile_id
                        AND up.authenticatable_type = 'client'
                        AND up.authenticatable_id   = e.client_id
                        AND up.blocked_at IS NULL
  JOIN roles          r  ON r.id  = up.role_id
                        AND r.name IN ('client-owner', 'client-manager', 'client-administrative')
 WHERE NOT EXISTS (SELECT 1 FROM establishment_user_profile x
                    WHERE x.establishment_id = p.establishment_id
                      AND x.user_profile_id  = up.id);

-- STEP 2B - full backfill (same rule as migration 2026_09_29_000002).
-- Links ALL non-blocked client staff to ALL establishments of their own client.
INSERT INTO establishment_user_profile (establishment_id, user_profile_id, created_at, updated_at)
SELECT e.id, up.id, NOW(), NOW()
  FROM establishments e
  JOIN user_profiles up ON up.authenticatable_type = 'client'
                       AND up.authenticatable_id   = e.client_id
                       AND up.blocked_at IS NULL
  JOIN roles r          ON r.id = up.role_id
                       AND r.name IN ('client-owner', 'client-manager', 'client-administrative')
 WHERE NOT EXISTS (SELECT 1 FROM establishment_user_profile x
                    WHERE x.establishment_id = e.id
                      AND x.user_profile_id  = up.id);

-- Check the "rows affected" of BOTH inserts above, then:
COMMIT;
-- (If anything looks wrong, run ROLLBACK; instead of COMMIT; - only valid before COMMIT.)


-- =====================================================================
-- SECTION 3 - VERIFICATION (read-only)
-- =====================================================================

-- 3A. Unlinked client managers of active programs. Expected: 0 (or only the 1C ambiguous cases).
SELECT COUNT(*) AS client_manager_rows_still_unlinked
  FROM program_manager pm
  JOIN programs p       ON p.id  = pm.program_id AND p.cancelled_at IS NULL
  JOIN user_profiles up ON up.id = pm.user_profile_id AND up.authenticatable_type = 'client'
 WHERE NOT EXISTS (SELECT 1 FROM establishment_user_profile x
                    WHERE x.establishment_id = p.establishment_id
                      AND x.user_profile_id  = up.id);

-- 3B. Equivalent of the manager-options endpoint (linked + not blocked) per active-program establishment.
SELECT DISTINCT p.establishment_id, e.name AS establishment,
       up.id AS user_profile_id, r.name AS role
  FROM programs p
  JOIN establishments e            ON e.id = p.establishment_id
  JOIN establishment_user_profile eup ON eup.establishment_id = e.id
  JOIN user_profiles up            ON up.id = eup.user_profile_id AND up.blocked_at IS NULL
  JOIN roles r                     ON r.id = up.role_id
 WHERE p.cancelled_at IS NULL
 ORDER BY p.establishment_id, up.id;

-- 3C. Every client-type manager of an active program is now offered by manager-options. Expected: 0 rows.
SELECT p.id AS program_id, pm.user_profile_id
  FROM program_manager pm
  JOIN programs p       ON p.id  = pm.program_id AND p.cancelled_at IS NULL
  JOIN user_profiles up ON up.id = pm.user_profile_id AND up.authenticatable_type = 'client' AND up.blocked_at IS NULL
 WHERE NOT EXISTS (SELECT 1 FROM establishment_user_profile x
                    WHERE x.establishment_id = p.establishment_id
                      AND x.user_profile_id  = up.id);

-- 3D. Client staff still without any establishment. Expected: 0 (only blocked or non-staff-role profiles may remain).
SELECT COUNT(*) AS client_profiles_without_any_establishment
  FROM user_profiles up
 WHERE up.authenticatable_type = 'client'
   AND NOT EXISTS (SELECT 1 FROM establishment_user_profile x WHERE x.user_profile_id = up.id);
