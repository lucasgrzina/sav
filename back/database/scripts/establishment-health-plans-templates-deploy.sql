-- =============================================================================
-- Deploy manual (sin SSH) — Plantillas de Plan Sanitario propias del vet (TKT-008)
-- Generado a partir de:
--   - back/database/migrations/2026_09_24_193508_add_vet_id_to_health_plan_templates_table.php
--   - back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php (sección "TKT-008")
--
-- Requiere que YA esté aplicado back/database/scripts/establishment-health-plans-deploy.sql
-- (tablas establishment_health_plans / establishment_health_plan_activities y permisos
-- base establishment-health-plans.{read,create,update,confirm}).
--
-- Ejecutar TODO el script de una sola vez, en orden, contra la base de prod.
-- La sección 1 (ALTER TABLE) fallará si ya corrió — no la ejecutes dos veces sobre
-- la misma base. La sección 3 (permisos) es idempotente y se puede re-ejecutar.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. DDL — ownership de plantillas (vet_id nullable = NULL sigue siendo global)
-- -----------------------------------------------------------------------------

ALTER TABLE `health_plan_templates`
  ADD COLUMN `vet_id` bigint unsigned NULL
    COMMENT 'null = plantilla global (catálogo super-admin); no nulo = plantilla propia del vet'
    AFTER `health_plan_category_id`;

ALTER TABLE `health_plan_templates`
  ADD CONSTRAINT `health_plan_templates_vet_id_foreign`
    FOREIGN KEY (`vet_id`) REFERENCES `vets` (`id`) ON DELETE CASCADE;

ALTER TABLE `health_plan_templates` ADD INDEX `health_plan_templates_vet_id_index` (`vet_id`);

-- -----------------------------------------------------------------------------
-- 2. Registro en la tabla `migrations` de Laravel
--    (para que `php artisan migrate` no intente re-correr esta migración el día
--    que vuelvas a tener acceso por SSH/deploy normal)
-- -----------------------------------------------------------------------------

SET @next_batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_24_193508_add_vet_id_to_health_plan_templates_table', @next_batch
WHERE NOT EXISTS (
  SELECT 1 FROM migrations WHERE migration = '2026_09_24_193508_add_vet_id_to_health_plan_templates_table'
);

-- -----------------------------------------------------------------------------
-- 3. Seed de permisos nuevos (equivalente al bloque "TKT-008" de
--    EstablishmentHealthPlanPermissionsSeeder). Idempotente: se puede re-ejecutar
--    sin duplicar filas.
--
--    OJO: NO reutiliza establishment-health-plans.{create,update} porque
--    vet-administrative ya los tiene para instancias (DEC-06) y debe quedar
--    SOLO LECTURA sobre plantillas (DEC-NEG-02 de TKT-008). Por eso vet-administrative
--    NO aparece en ningún INSERT de esta sección 3.
-- -----------------------------------------------------------------------------

INSERT INTO permissions (guid, name, guard_name, created_at, updated_at)
SELECT UUID(), t.name, 'web', NOW(), NOW()
FROM (
  SELECT 'establishment-health-plans.templates.create' AS name UNION ALL
  SELECT 'establishment-health-plans.templates.update' AS name UNION ALL
  SELECT 'establishment-health-plans.templates.delete' AS name
) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM permissions p WHERE p.name = t.name AND p.guard_name = 'web'
);

-- super-admin: los 3 permisos nuevos
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id
FROM permissions p
CROSS JOIN roles r
WHERE p.guard_name = 'web'
  AND p.name IN ('establishment-health-plans.templates.create', 'establishment-health-plans.templates.update', 'establishment-health-plans.templates.delete')
  AND r.name = 'super-admin';

-- vet: los 3 permisos nuevos (TKT-008 / DEC-NEG-02)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id
FROM permissions p
CROSS JOIN roles r
WHERE p.guard_name = 'web'
  AND p.name IN ('establishment-health-plans.templates.create', 'establishment-health-plans.templates.update', 'establishment-health-plans.templates.delete')
  AND r.name = 'vet';

-- vet-assistant: los 3 permisos nuevos (TKT-008 / DEC-NEG-02)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id
FROM permissions p
CROSS JOIN roles r
WHERE p.guard_name = 'web'
  AND p.name IN ('establishment-health-plans.templates.create', 'establishment-health-plans.templates.update', 'establishment-health-plans.templates.delete')
  AND r.name = 'vet-assistant';

-- vet-administrative: deliberadamente SIN INSERT — queda solo-lectura sobre plantillas.

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
