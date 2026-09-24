-- =============================================================================
-- Deploy manual (sin SSH) — Planes Sanitarios por Establecimiento (Fase 1)
-- Generado a partir de:
--   - back/database/migrations/2026_09_24_000001_create_establishment_health_plans_table.php
--   - back/database/migrations/2026_09_24_000002_create_establishment_health_plan_activities_table.php
--   - back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php
--
-- Fase 2 (alertas) NO agrega migraciones ni seeders — reutiliza tablas existentes
-- (alerts, alert_recipients). No hay nada que correr acá para Fase 2.
--
-- Ejecutar TODO el script de una sola vez, en orden, contra la base de prod.
-- Es seguro re-ejecutarlo solo en la sección 3 (permisos) e idempotente en la 2
-- (migrations) — la sección 1 (CREATE TABLE) fallará si ya corrió, como es
-- esperable: no la ejecutes dos veces sobre la misma base.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. DDL — tablas nuevas
-- -----------------------------------------------------------------------------

CREATE TABLE `establishment_health_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `guid` char(36) NOT NULL,
  `vet_id` bigint unsigned NOT NULL,
  `client_id` bigint unsigned NOT NULL,
  `establishment_id` bigint unsigned NOT NULL,
  `health_plan_template_id` bigint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL COMMENT 'año de inicio del ciclo ganadero (ej. 2026 = jul/2026-jun/2027 en AR)',
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `created_by_user_id` bigint unsigned NULL,
  `cancelled_at` timestamp NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';

ALTER TABLE `establishment_health_plans`
  ADD CONSTRAINT `establishment_health_plans_vet_id_foreign` FOREIGN KEY (`vet_id`) REFERENCES `vets` (`id`) ON DELETE CASCADE;

ALTER TABLE `establishment_health_plans`
  ADD CONSTRAINT `establishment_health_plans_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

ALTER TABLE `establishment_health_plans`
  ADD CONSTRAINT `establishment_health_plans_establishment_id_foreign` FOREIGN KEY (`establishment_id`) REFERENCES `establishments` (`id`) ON DELETE CASCADE;

ALTER TABLE `establishment_health_plans`
  ADD CONSTRAINT `establishment_health_plans_health_plan_template_id_foreign` FOREIGN KEY (`health_plan_template_id`) REFERENCES `health_plan_templates` (`id`) ON DELETE RESTRICT;

ALTER TABLE `establishment_health_plans`
  ADD CONSTRAINT `establishment_health_plans_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `establishment_health_plans` ADD INDEX `establishment_health_plans_vet_id_index` (`vet_id`);
ALTER TABLE `establishment_health_plans` ADD INDEX `establishment_health_plans_vet_id_cancelled_at_index` (`vet_id`, `cancelled_at`);
ALTER TABLE `establishment_health_plans` ADD INDEX `ehp_dedup_lookup_idx` (`establishment_id`, `health_plan_template_id`, `year`);
ALTER TABLE `establishment_health_plans` ADD UNIQUE `establishment_health_plans_guid_unique` (`guid`);

CREATE TABLE `establishment_health_plan_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `guid` char(36) NOT NULL,
  `establishment_health_plan_id` bigint unsigned NOT NULL,
  `health_activity_id` bigint unsigned NOT NULL,
  `month` tinyint unsigned NOT NULL COMMENT '1-12, mes calendario — copiado del pivot months del template al instanciar (DEC-05)',
  `due_date` date NOT NULL COMMENT 'fecha concreta calculada al instanciar via App\\Support\\HealthPlanYear',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `require_confirmation` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'regla dura #7 — siempre true en Fase 1, columna explícita para no hardcodear en código',
  `confirmed_at` timestamp NULL,
  `confirmed_by_profile_id` bigint unsigned NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';

ALTER TABLE `establishment_health_plan_activities`
  ADD CONSTRAINT `ehp_activities_plan_id_foreign` FOREIGN KEY (`establishment_health_plan_id`) REFERENCES `establishment_health_plans` (`id`) ON DELETE CASCADE;

ALTER TABLE `establishment_health_plan_activities`
  ADD CONSTRAINT `ehp_activities_health_activity_id_foreign` FOREIGN KEY (`health_activity_id`) REFERENCES `health_activities` (`id`) ON DELETE RESTRICT;

ALTER TABLE `establishment_health_plan_activities`
  ADD CONSTRAINT `ehp_activities_confirmed_by_profile_id_foreign` FOREIGN KEY (`confirmed_by_profile_id`) REFERENCES `user_profiles` (`id`) ON DELETE SET NULL;

ALTER TABLE `establishment_health_plan_activities` ADD INDEX `ehp_activities_plan_month_idx` (`establishment_health_plan_id`, `month`);
ALTER TABLE `establishment_health_plan_activities` ADD UNIQUE `establishment_health_plan_activities_guid_unique` (`guid`);

-- -----------------------------------------------------------------------------
-- 2. Registro en la tabla `migrations` de Laravel
--    (para que `php artisan migrate` no intente re-correr estas 2 migraciones
--    el día que vuelvas a tener acceso por SSH/deploy normal)
-- -----------------------------------------------------------------------------

SET @next_batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_24_000001_create_establishment_health_plans_table', @next_batch
WHERE NOT EXISTS (
  SELECT 1 FROM migrations WHERE migration = '2026_09_24_000001_create_establishment_health_plans_table'
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_24_000002_create_establishment_health_plan_activities_table', @next_batch
WHERE NOT EXISTS (
  SELECT 1 FROM migrations WHERE migration = '2026_09_24_000002_create_establishment_health_plan_activities_table'
);

-- -----------------------------------------------------------------------------
-- 3. Seed de permisos (equivalente a EstablishmentHealthPlanPermissionsSeeder)
--    Idempotente: se puede re-ejecutar sin duplicar filas.
-- -----------------------------------------------------------------------------

INSERT INTO permissions (guid, name, guard_name, created_at, updated_at)
SELECT UUID(), t.name, 'web', NOW(), NOW()
FROM (
  SELECT 'establishment-health-plans.read'   AS name UNION ALL
  SELECT 'establishment-health-plans.create' AS name UNION ALL
  SELECT 'establishment-health-plans.update' AS name UNION ALL
  SELECT 'establishment-health-plans.confirm' AS name
) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM permissions p WHERE p.name = t.name AND p.guard_name = 'web'
);

-- super-admin: los 4 permisos
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id
FROM permissions p
CROSS JOIN roles r
WHERE p.guard_name = 'web'
  AND p.name IN ('establishment-health-plans.read', 'establishment-health-plans.create', 'establishment-health-plans.update', 'establishment-health-plans.confirm')
  AND r.name = 'super-admin';

-- vet: los 4 permisos (DU-07 + DU-08)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id
FROM permissions p
CROSS JOIN roles r
WHERE p.guard_name = 'web'
  AND p.name IN ('establishment-health-plans.read', 'establishment-health-plans.create', 'establishment-health-plans.update', 'establishment-health-plans.confirm')
  AND r.name = 'vet';

-- vet-assistant: los 4 permisos (DU-07 + DU-08)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id
FROM permissions p
CROSS JOIN roles r
WHERE p.guard_name = 'web'
  AND p.name IN ('establishment-health-plans.read', 'establishment-health-plans.create', 'establishment-health-plans.update', 'establishment-health-plans.confirm')
  AND r.name = 'vet-assistant';

-- vet-administrative: instancia/ve/edita pero NUNCA confirma (DEC-06)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id
FROM permissions p
CROSS JOIN roles r
WHERE p.guard_name = 'web'
  AND p.name IN ('establishment-health-plans.read', 'establishment-health-plans.create', 'establishment-health-plans.update')
  AND r.name = 'vet-administrative';

-- client-owner: solo confirma (DU-07 opción A — sin portal autenticado hoy, sin efecto práctico todavía)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id
FROM permissions p
CROSS JOIN roles r
WHERE p.guard_name = 'web'
  AND p.name = 'establishment-health-plans.confirm'
  AND r.name = 'client-owner';

-- client-manager: solo confirma (DU-07 opción A — sin portal autenticado hoy, sin efecto práctico todavía)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id
FROM permissions p
CROSS JOIN roles r
WHERE p.guard_name = 'web'
  AND p.name = 'establishment-health-plans.confirm'
  AND r.name = 'client-manager';

COMMIT;

-- =============================================================================
-- IMPORTANTE — caché de permisos de Spatie
-- Este script inserta directo en `permissions`/`role_has_permissions`, sin pasar
-- por el paquete Spatie, que normalmente invalida su caché al llamar
-- syncPermissions()/givePermissionTo(). Si tu .env de prod tiene el cache de
-- permisos activo (default: sí, ~24hs), los permisos nuevos pueden no verse
-- reflejados en la app hasta que expire esa caché — a menos que tengas forma de
-- limpiarla sin SSH (endpoint propio, redeploy que reinicie el store de caché, etc).
-- =============================================================================
