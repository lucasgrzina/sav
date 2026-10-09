-- =============================================================================
-- Deploy manual (sin SSH) — Catálogo de Protocolos legacy (MOET, IATF, FIV, Hernicol)
-- Generado a partir de LegacyProtocolSeeder.php + database/scripts/*_migration.json
--
-- 100% ADITIVO: no borra ni trunca nada. Si un Protocol con el mismo nombre ya
-- existe (global, vet_id IS NULL) para el país, se saltea ESE protocolo entero
-- (y sus tasks/alerts) para no duplicar. Es seguro re-ejecutar el script completo.
--
-- Requiere que ya existan: el país Argentina (iso_code=AR) y al menos un usuario
-- con rol super-admin — igual que exige el seeder original.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

SET @ar_country_id := (SELECT id FROM countries WHERE iso_code = 'AR' LIMIT 1);
SET @super_admin_id := (SELECT mhr.model_id FROM model_has_roles mhr JOIN roles r ON r.id = mhr.role_id WHERE r.name = 'super-admin' AND r.guard_name = 'web' AND mhr.model_type = 'App\\Models\\User' LIMIT 1);

-- ----------------------------------------------------------------------------
-- Fuente: moet_migration.json
-- ----------------------------------------------------------------------------

-- Technique root: MOET
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'MOET', 'Fecha de COLECTA / TE', 'technique', NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques WHERE name = 'MOET' AND parent_id IS NULL);

-- Technique child: Lavaje y Congelación de Embriones (LCE) (parent: MOET)
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'Lavaje y Congelación de Embriones (LCE)', 'Fecha de colecta de embriones', 'technique', (SELECT id FROM techniques WHERE name = 'MOET' AND parent_id IS NULL), 'Protocolo solo Donantes', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques t2 JOIN techniques root2 ON root2.id = t2.parent_id WHERE t2.name = 'Lavaje y Congelación de Embriones (LCE)' AND root2.name = 'MOET');

-- Technique child: Lavaje y Transferencia de Embriones (LCE) (parent: MOET)
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'Lavaje y Transferencia de Embriones (LCE)', 'Fecha de COLECTA / TE', 'technique', (SELECT id FROM techniques WHERE name = 'MOET' AND parent_id IS NULL), 'Protocolo para Donantes y Receptoras', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques t2 JOIN techniques root2 ON root2.id = t2.parent_id WHERE t2.name = 'Lavaje y Transferencia de Embriones (LCE)' AND root2.name = 'MOET');

-- Technique child: Transferencia de Embriones Congelados (TEC) (parent: MOET)
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'Transferencia de Embriones Congelados (TEC)', 'Fecha de transferencia de embriones', 'technique', (SELECT id FROM techniques WHERE name = 'MOET' AND parent_id IS NULL), 'Protocolo solo Receptoras', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques t2 JOIN techniques root2 ON root2.id = t2.parent_id WHERE t2.name = 'Transferencia de Embriones Congelados (TEC)' AND root2.name = 'MOET');

-- =====================================================================
-- Protocol: SUPEROVULACION - P600 - PLUSET 600UI - 60 c.c. (1:50)
-- =====================================================================
SET @p_exists := (SELECT COUNT(*) FROM protocols WHERE name = 'SUPEROVULACION - P600 - PLUSET 600UI - 60 c.c. (1:50)' AND country_id = @ar_country_id AND vet_id IS NULL);

INSERT INTO protocols (guid, technique_id, country_id, vet_id, created_by_type, created_by_id, name, color, created_at, updated_at)
SELECT UUID(), (SELECT id FROM techniques WHERE name = 'Lavaje y Congelación de Embriones (LCE)' AND parent_id IS NOT NULL), @ar_country_id, NULL, 'superadmin', @super_admin_id, 'SUPEROVULACION - P600 - PLUSET 600UI - 60 c.c. (1:50)', '#ffff00', NOW(), NOW()
WHERE @p_exists = 0;

SET @protocol_id := (SELECT id FROM protocols WHERE name = 'SUPEROVULACION - P600 - PLUSET 600UI - 60 c.c. (1:50)' AND country_id = @ar_country_id AND vet_id IS NULL LIMIT 1);

-- Task #0: COLOCAR DISPOSITIVO INTRAVAGINAL
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'COLOCAR DISPOSITIVO INTRAVAGINAL', 15, 'before', '16:00:00', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #1: PROGESTERONA 10 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PROGESTERONA 10 cc', 15, 'before', '16:00:00', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #2: 17 B ESTRADIOL 5 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, '17 B ESTRADIOL 5 cc', 15, 'before', '16:00:00', 0, 2, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #3: PLUSET 9
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 9', 12, 'before', '08:00:00', 0, 3, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #4: PLUSET 9
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 9', 12, 'before', '18:00:00', 0, 4, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #5: PLUSET 8
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 8', 11, 'before', '08:00:00', 0, 5, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #6: PLUSET 8
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 8', 11, 'before', '18:00:00', 0, 6, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #7: PLUSET 6
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 6', 10, 'before', '08:00:00', 0, 7, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #8: PLUSET 6
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 6', 10, 'before', '18:00:00', 0, 8, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #9: PLUSET 5
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 5', 9, 'before', '08:00:00', 0, 9, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #10: CICLASE 2cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'CICLASE 2cc', 9, 'before', '08:00:00', 1, 10, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #11: PLUSET 5
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 5', 9, 'before', '18:00:00', 0, 11, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #12: CICLASE 2cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'CICLASE 2cc', 9, 'before', '18:00:00', 1, 12, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #13: RETIRAR CIDR No olvidar
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'RETIRAR CIDR No olvidar', 8, 'before', '08:00:00', 1, 13, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #14: PLUSET 3
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 3', 8, 'before', '08:00:00', 0, 14, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #15: PLUSET 3
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 3', 8, 'before', '18:00:00', 0, 15, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #16: CELO
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'CELO', 7, 'before', '08:00:00', 0, 16, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #17: GESTAR / GNRH 3 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'GESTAR / GNRH 3 cc', 7, 'before', '08:00:00', 0, 17, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #18: INSEMINACION 1 Pajuela
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'INSEMINACION 1 Pajuela', 7, 'before', '18:00:00', 0, 18, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #19: INSEMINACION 1 Pajuela
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'INSEMINACION 1 Pajuela', 6, 'before', '08:00:00', 0, 19, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #20: INSEMINACION 1 Pajuela
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'INSEMINACION 1 Pajuela', 6, 'before', '14:00:00', 0, 20, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #21: Colecta
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Colecta', 0, 'before', '08:00:00', 0, 21, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #0 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 0), 1, 'before', '20:00:00', CAST('["client-manager","client-owner"]' AS JSON), 'Mañana comienza el programa', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #0 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 0), 1, 'before', '07:00:00', CAST('["client-manager"]' AS JSON), 'Hoy comienza el programa', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 1, 'before', '20:00:00', CAST('["client-manager"]' AS JSON), 'Mañana se debe colocar Pluset 9', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 0, 'before', '07:00:00', CAST('["client-manager"]' AS JSON), 'Hoy se debe colocar Pluset 9', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #7 (offset_days=0, after)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 7), 0, 'after', '20:00:00', CAST('["client-manager"]' AS JSON), 'Mañana se debe colocar Ciclase 2cc', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #9 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 9), 0, 'before', '07:00:00', CAST('["client-manager"]' AS JSON), 'Hoy se debe colocar Ciclase 2cc', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #9 (offset_days=0, after)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 9), 0, 'after', '20:00:00', CAST('["client-manager"]' AS JSON), 'Mañana se debe retirar el dispositivo CIDR', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #13 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 13), 0, 'before', '07:00:00', CAST('["client-manager"]' AS JSON), 'Hoy se debe retirar el dispositivo CIDR', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #13 (offset_days=0, after)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 13), 0, 'after', '20:00:00', CAST('["client-manager"]' AS JSON), 'Mañana se insemina', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #16 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 16), 0, 'before', '07:00:00', CAST('["client-manager"]' AS JSON), 'Hoy se insemina', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- =====================================================================
-- Protocol: SUPEROVULACION - P500 - PLUSET 500UI - 50 c.c. (1:50)
-- =====================================================================
SET @p_exists := (SELECT COUNT(*) FROM protocols WHERE name = 'SUPEROVULACION - P500 - PLUSET 500UI - 50 c.c. (1:50)' AND country_id = @ar_country_id AND vet_id IS NULL);

INSERT INTO protocols (guid, technique_id, country_id, vet_id, created_by_type, created_by_id, name, color, created_at, updated_at)
SELECT UUID(), (SELECT id FROM techniques WHERE name = 'Lavaje y Congelación de Embriones (LCE)' AND parent_id IS NOT NULL), @ar_country_id, NULL, 'superadmin', @super_admin_id, 'SUPEROVULACION - P500 - PLUSET 500UI - 50 c.c. (1:50)', '#C0C0C0', NOW(), NOW()
WHERE @p_exists = 0;

SET @protocol_id := (SELECT id FROM protocols WHERE name = 'SUPEROVULACION - P500 - PLUSET 500UI - 50 c.c. (1:50)' AND country_id = @ar_country_id AND vet_id IS NULL LIMIT 1);

-- Task #0: COLOCAR DISPOSITIVO INTRAVAGINAL
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'COLOCAR DISPOSITIVO INTRAVAGINAL', 15, 'before', '16:00:00', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #1: PROGESTERONA 10 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PROGESTERONA 10 cc', 15, 'before', '16:00:00', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #2: 17 B ESTRADIOL 5 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, '17 B ESTRADIOL 5 cc', 15, 'before', '16:00:00', 0, 2, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #3: PLUSET 8 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 8 cc', 12, 'before', '08:00:00', 0, 3, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #4: PLUSET 8 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 8 cc', 12, 'before', '18:00:00', 0, 4, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #5: PLUSET 7 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 7 cc', 11, 'before', '08:00:00', 0, 5, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #6: PLUSET 7 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 7 cc', 11, 'before', '18:00:00', 0, 6, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #7: PLUSET 5 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 5 cc', 10, 'before', '08:00:00', 0, 7, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #8: PLUSET 5 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 5 cc', 10, 'before', '18:00:00', 0, 8, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #9: PLUSET 3 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 3 cc', 9, 'before', '08:00:00', 0, 9, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #10: PROSTAGLANDINA 2 CC
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PROSTAGLANDINA 2 CC', 9, 'before', '08:00:00', 1, 10, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #11: PLUSET 3 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 3 cc', 9, 'before', '18:00:00', 0, 11, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #12: PROSTAGLANDINA 2 CC
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PROSTAGLANDINA 2 CC', 9, 'before', '18:00:00', 1, 12, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #13: RETIRAR CIDR No olvidar
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'RETIRAR CIDR No olvidar', 8, 'before', '08:00:00', 1, 13, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #14: PLUSET 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 2 cc', 8, 'before', '08:00:00', 0, 14, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #15: PLUSET 2 cc + PINTURA BASE DE COLA
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PLUSET 2 cc + PINTURA BASE DE COLA', 8, 'before', '18:00:00', 0, 15, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #16: GNRH 3 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'GNRH 3 cc', 7, 'before', '18:00:00', 0, 16, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #17: INSEMINACION 1 Pajuela
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'INSEMINACION 1 Pajuela', 7, 'before', '18:00:00', 0, 17, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #18: INSEMINACION 1 Pajuela
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'INSEMINACION 1 Pajuela', 6, 'before', '08:00:00', 0, 18, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #19: INSEMINACION 1 Pajuela
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'INSEMINACION 1 Pajuela', 6, 'before', '14:00:00', 0, 19, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #20: Colecta
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Colecta', 0, 'before', '08:00:00', 0, 20, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #0 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 0), 1, 'before', '20:00:00', CAST('["client-manager","client-owner"]' AS JSON), 'MAÑANA AM INICIAN "D" ¿Tenés planillas impresas e insumos?', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #0 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 0), 0, 'before', '07:00:00', CAST('["client-manager"]' AS JSON), 'BUEN DIA! Hoy iniciamos DONANTES. BUEN COMIENZO!!', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 1, 'before', '20:00:00', CAST('["client-manager"]' AS JSON), 'BUEN DIA! MAÑANA AM INICIO SUPEROVULACION.', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 0, 'before', '07:00:00', CAST('["client-manager"]' AS JSON), 'BUEN DIA! Hoy iniciamos con el PLUSET. BUEN COMIENZO!!', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #9 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 9), 0, 'before', '07:00:00', CAST('["client-manager"]' AS JSON), 'BUEN DÍA! IMPORTANTE LA PROSTAGLANDINA Y EL PLUSET DÍA DE HOY!', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #13 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 13), 0, 'before', '07:00:00', CAST('["client-manager"]' AS JSON), 'BUEN DÍA! IMPORTANTE EL DE HOY!', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #13 (offset_days=0, after)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 13), 0, 'after', '18:00:00', CAST('["client-manager"]' AS JSON), 'BUENAS TARDES! MAÑANA DIA DE COLECTAS, ENCERRAR A PRIMERA HORA POR LA MAÑANA', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;
-- ----------------------------------------------------------------------------
-- Fuente: iatf_migration.json
-- ----------------------------------------------------------------------------

-- Technique root: IATF
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'IATF', 'Fecha de inseminación', 'technique', NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques WHERE name = 'IATF' AND parent_id IS NULL);

-- Technique child: Vaquillonas - Carne (parent: IATF)
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'Vaquillonas - Carne', 'Fecha de inseminación', 'technique', (SELECT id FROM techniques WHERE name = 'IATF' AND parent_id IS NULL), 'Protocolo 1', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques t2 JOIN techniques root2 ON root2.id = t2.parent_id WHERE t2.name = 'Vaquillonas - Carne' AND root2.name = 'IATF');

-- Technique child: Vacas - Carne (parent: IATF)
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'Vacas - Carne', 'Fecha de inseminación', 'technique', (SELECT id FROM techniques WHERE name = 'IATF' AND parent_id IS NULL), 'Protocolo 2', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques t2 JOIN techniques root2 ON root2.id = t2.parent_id WHERE t2.name = 'Vacas - Carne' AND root2.name = 'IATF');

-- Technique child: Vaquillonas - Leche (parent: IATF)
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'Vaquillonas - Leche', 'Fecha de inseminación', 'technique', (SELECT id FROM techniques WHERE name = 'IATF' AND parent_id IS NULL), 'Protocolo 3', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques t2 JOIN techniques root2 ON root2.id = t2.parent_id WHERE t2.name = 'Vaquillonas - Leche' AND root2.name = 'IATF');

-- Technique child: Vacas - Leche (parent: IATF)
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'Vacas - Leche', 'Fecha de inseminación', 'technique', (SELECT id FROM techniques WHERE name = 'IATF' AND parent_id IS NULL), 'Protocolo 4', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques t2 JOIN techniques root2 ON root2.id = t2.parent_id WHERE t2.name = 'Vacas - Leche' AND root2.name = 'IATF');

-- =====================================================================
-- Protocol: Protocolo SINCRONIZACION DE CELOS para IATF ( INSEMINACION A TIEMPO FIJO )
-- =====================================================================
SET @p_exists := (SELECT COUNT(*) FROM protocols WHERE name = 'Protocolo SINCRONIZACION DE CELOS para IATF ( INSEMINACION A TIEMPO FIJO )' AND country_id = @ar_country_id AND vet_id IS NULL);

INSERT INTO protocols (guid, technique_id, country_id, vet_id, created_by_type, created_by_id, name, color, created_at, updated_at)
SELECT UUID(), (SELECT id FROM techniques WHERE name = 'Vaquillonas - Carne' AND parent_id IS NOT NULL), @ar_country_id, NULL, 'superadmin', @super_admin_id, 'Protocolo SINCRONIZACION DE CELOS para IATF ( INSEMINACION A TIEMPO FIJO )', '#ffff00', NOW(), NOW()
WHERE @p_exists = 0;

SET @protocol_id := (SELECT id FROM protocols WHERE name = 'Protocolo SINCRONIZACION DE CELOS para IATF ( INSEMINACION A TIEMPO FIJO )' AND country_id = @ar_country_id AND vet_id IS NULL LIMIT 1);

-- Task #0: Selección (Tacto preservicio), alojamiento y suplementación.
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Selección (Tacto preservicio), alojamiento y suplementación. Sanidad (Vacuna Viral Reproductiva y VIT-MIN)', 32, 'before', '08:00:00', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #1: Colocar Dispositivo INTRAVAGINAL (De 500 gr)
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Colocar Dispositivo INTRAVAGINAL (De 500 gr)', 10, 'before', '07:00:00', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #2: Inyectar BENZOATO de ESTRADIOL 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar BENZOATO de ESTRADIOL 2 cc', 10, 'before', '07:00:00', 0, 2, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #3: Retirar Dispositivo INTRAVAGINAL
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Retirar Dispositivo INTRAVAGINAL', 2, 'before', '07:00:00', 0, 3, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #4: Inyectar CIPIONATO DE ESTRADIOL 1 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar CIPIONATO DE ESTRADIOL 1 cc', 2, 'before', '07:00:00', 0, 4, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #5: Inyectar eCG de 300 a 400 IU. Dependiendo de la condicion Co
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar eCG de 300 a 400 IU. Dependiendo de la condicion Corporal.', 2, 'before', '07:00:00', 0, 5, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #6: PINTURA en la base de la COLA
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PINTURA en la base de la COLA', 2, 'before', '07:00:00', 1, 6, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #7: Inyectar GnRH 2,5 cc a las pintadas
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar GnRH 2,5 cc a las pintadas', 0, 'before', '08:00:00', 1, 7, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #8: Inseminar grupo con pintura PM
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inseminar grupo con pintura PM', 0, 'before', '08:00:00', 1, 8, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #1 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 1), 1, 'before', '08:00:00', CAST('["client-manager","client-owner"]' AS JSON), 'MAÑANA INICIAN TRATAMIENTO DE IATF ¿Tenés planillas impresas e insumos?', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=5, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 5, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'NO OLVIDAR LARGAR LOS TOROS A REPASO', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 1, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'MAÑANA CONTINÚA TRATAMIENTO DE ITAF. ¿Tenés todos los insumos?', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #7 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 7), 1, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'MAÑANA INSEMINAMOS, ENCERRAR POR LA TARDE', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #7 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 7), 0, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'INSEMINACION a despintadas 48 hs del retiro.', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- =====================================================================
-- Protocol: CONVENCIONAL VAQUILLONA SECA
-- =====================================================================
SET @p_exists := (SELECT COUNT(*) FROM protocols WHERE name = 'CONVENCIONAL VAQUILLONA SECA' AND country_id = @ar_country_id AND vet_id IS NULL);

INSERT INTO protocols (guid, technique_id, country_id, vet_id, created_by_type, created_by_id, name, color, created_at, updated_at)
SELECT UUID(), (SELECT id FROM techniques WHERE name = 'Vaquillonas - Carne' AND parent_id IS NOT NULL), @ar_country_id, NULL, 'superadmin', @super_admin_id, 'CONVENCIONAL VAQUILLONA SECA', '#ffff00', NOW(), NOW()
WHERE @p_exists = 0;

SET @protocol_id := (SELECT id FROM protocols WHERE name = 'CONVENCIONAL VAQUILLONA SECA' AND country_id = @ar_country_id AND vet_id IS NULL LIMIT 1);

-- Task #0: Selección (Tacto preservicio), alojamiento y suplementación.
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Selección (Tacto preservicio), alojamiento y suplementación. Sanidad (Vacuna Viral Reproductiva y VIT-MIN)', 32, 'before', '08:00:00', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #1: Colocar Dispositivo INTRAVAGINAL (De 600 gr a 1 gr)
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Colocar Dispositivo INTRAVAGINAL (De 600 gr a 1 gr)', 9, 'before', '07:00:00', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #2: Inyectar BENZOATO de ESTRADIOL 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar BENZOATO de ESTRADIOL 2 cc', 9, 'before', '07:00:00', 0, 2, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #3: Retirar Dispositivo INTRAVAGINAL
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Retirar Dispositivo INTRAVAGINAL', 2, 'before', '07:00:00', 0, 3, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #4: Inyectar PROSTAGLANDINA 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar PROSTAGLANDINA 2 cc', 2, 'before', '07:00:00', 0, 4, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #5: Inyectar CIPIONATO DE ESTRADIOL 1 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar CIPIONATO DE ESTRADIOL 1 cc', 2, 'before', '07:00:00', 0, 5, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #6: Inyectar eCG de 300 a 400 IU. Dependiendo de la condicion Co
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar eCG de 300 a 400 IU. Dependiendo de la condicion Corporal.', 2, 'before', '07:00:00', 0, 6, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #7: PINTURA en la base de la COLA
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PINTURA en la base de la COLA', 2, 'before', '07:00:00', 1, 7, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #8: Inyectar GnRH 2,5 cc a las pintadas
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar GnRH 2,5 cc a las pintadas', 0, 'before', '08:00:00', 1, 8, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #9: Inseminar grupo con pintura PM
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inseminar grupo con pintura PM', 0, 'before', '08:00:00', 1, 9, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #1 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 1), 1, 'before', '08:00:00', CAST('["client-manager","client-owner"]' AS JSON), 'MAÑANA INICIAN TRATAMIENTO DE IATF ¿Tenés planillas impresas e insumos?', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=5, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 5, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'NO OLVIDAR LARGAR LOS TOROS A REPASO', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 1, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'MAÑANA CONTINÚA TRATAMIENTO DE ITAF. ¿Tenés todos los insumos?', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #8 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 8), 1, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'MAÑANA INSEMINAMOS, ENCERRAR POR LA TARDE', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #8 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 8), 0, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'INSEMINACION a despintadas 48 hs del retiro.', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- =====================================================================
-- Protocol: J-SYNCH (VAQUILLONAS)
-- =====================================================================
SET @p_exists := (SELECT COUNT(*) FROM protocols WHERE name = 'J-SYNCH (VAQUILLONAS)' AND country_id = @ar_country_id AND vet_id IS NULL);

INSERT INTO protocols (guid, technique_id, country_id, vet_id, created_by_type, created_by_id, name, color, created_at, updated_at)
SELECT UUID(), (SELECT id FROM techniques WHERE name = 'Vaquillonas - Carne' AND parent_id IS NOT NULL), @ar_country_id, NULL, 'superadmin', @super_admin_id, 'J-SYNCH (VAQUILLONAS)', '#ffff00', NOW(), NOW()
WHERE @p_exists = 0;

SET @protocol_id := (SELECT id FROM protocols WHERE name = 'J-SYNCH (VAQUILLONAS)' AND country_id = @ar_country_id AND vet_id IS NULL LIMIT 1);

-- Task #0: Selección (Tacto preservicio), alojamiento y suplementación.
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Selección (Tacto preservicio), alojamiento y suplementación. Sanidad (Vacuna Viral Reproductiva y VIT-MIN)', 32, 'before', '08:00:00', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #1: Colocar Dispositivo INTRAVAGINAL (De 500 gr)
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Colocar Dispositivo INTRAVAGINAL (De 500 gr)', 9, 'before', '14:00:00', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #2: Inyectar BENZOATO de ESTRADIOL (2mg) 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar BENZOATO de ESTRADIOL (2mg) 2 cc', 9, 'before', '14:00:00', 0, 2, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #3: Retirar Dispositivo INTRAVAGINAL
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Retirar Dispositivo INTRAVAGINAL', 3, 'before', '07:00:00', 0, 3, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #4: Inyectar PROSTAGLANDINA 0,150mg (2 cc)
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar PROSTAGLANDINA 0,150mg (2 cc)', 3, 'before', '07:00:00', 0, 4, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #5: Inyectar eCG de 200 IU. Dependiendo de la condicion Corporal
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar eCG de 200 IU. Dependiendo de la condicion Corporal.', 3, 'before', '07:00:00', 0, 5, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #6: PINTURA en la base de la COLA
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'PINTURA en la base de la COLA', 3, 'before', '07:00:00', 1, 6, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #7: Inyectar GnRH 2,5 cc a las pintadas SIN CELO
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar GnRH 2,5 cc a las pintadas SIN CELO', 0, 'before', '08:00:00', 1, 7, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #8: INSEMINACION grupo con pintura PM a las 72 hs
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'INSEMINACION grupo con pintura PM a las 72 hs', 0, 'before', '14:00:00', 1, 8, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #1 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 1), 1, 'before', '08:00:00', CAST('["client-manager","client-owner"]' AS JSON), 'MAÑANA INICIAN TRATAMIENTO DE IATF ¿Tenés planillas impresas e insumos?', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=4, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 4, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'NO OLVIDAR LARGAR LOS TOROS A REPASO', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #3 (offset_days=0, after)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 3), 0, 'after', '08:00:00', CAST('["client-manager"]' AS JSON), 'MAÑANA CONTINÚA TRATAMIENTO DE ITAF. ¿Tenés todos los insumos?', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #7 (offset_days=1, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 7), 1, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'MAÑANA INSEMINAMOS, ENCERRAR POR LA TARDE', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Alert para task #7 (offset_days=0, before)
INSERT INTO protocol_task_alerts (guid, protocol_task_id, offset_days, time_of_day, time, roles, message, require_confirmation, sort_order, created_at, updated_at)
SELECT UUID(), (SELECT id FROM protocol_tasks WHERE protocol_id = @protocol_id AND sort_order = 7), 0, 'before', '08:00:00', CAST('["client-manager"]' AS JSON), 'INSEMINACION a despintadas 48 hs del retiro.', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;
-- ----------------------------------------------------------------------------
-- Fuente: fiv_migration.json
-- ----------------------------------------------------------------------------

-- Technique root: FIV
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'FIV', 'Fecha de OPU', 'technique', NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques WHERE name = 'FIV' AND parent_id IS NULL);
-- ----------------------------------------------------------------------------
-- Fuente: hernicol_iatf_migration.json
-- ----------------------------------------------------------------------------

-- Technique root: IATF
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'IATF', 'Fecha de inseminación', 'technique', NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques WHERE name = 'IATF' AND parent_id IS NULL);

-- Technique child: Vacas - Carne (parent: IATF)
INSERT INTO techniques (guid, name, target_date_name, type, parent_id, protocols_name, created_at, updated_at)
SELECT UUID(), 'Vacas - Carne', 'Fecha de inseminación', 'technique', (SELECT id FROM techniques WHERE name = 'IATF' AND parent_id IS NULL), 'Protocolo 2', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM techniques t2 JOIN techniques root2 ON root2.id = t2.parent_id WHERE t2.name = 'Vacas - Carne' AND root2.name = 'IATF');

-- =====================================================================
-- Protocol: Rodeo 2° Servicio
-- =====================================================================
SET @p_exists := (SELECT COUNT(*) FROM protocols WHERE name = 'Rodeo 2° Servicio' AND country_id = @ar_country_id AND vet_id IS NULL);

INSERT INTO protocols (guid, technique_id, country_id, vet_id, created_by_type, created_by_id, name, color, created_at, updated_at)
SELECT UUID(), (SELECT id FROM techniques WHERE name = 'Vacas - Carne' AND parent_id IS NOT NULL), @ar_country_id, NULL, 'superadmin', @super_admin_id, 'Rodeo 2° Servicio', '#ffc000', NOW(), NOW()
WHERE @p_exists = 0;

SET @protocol_id := (SELECT id FROM protocols WHERE name = 'Rodeo 2° Servicio' AND country_id = @ar_country_id AND vet_id IS NULL LIMIT 1);

-- Task #0: Colocar Dispositivo intravaginal
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Colocar Dispositivo intravaginal', 10, 'before', '08:00:00', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #1: Inyectar Benzoato de estradiol 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar Benzoato de estradiol 2 cc', 10, 'before', '08:00:00', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #2: Inyectar GnRH 3 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar GnRH 3 cc', 10, 'before', '08:00:00', 0, 2, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #3: Inyectar Prostaglandina 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar Prostaglandina 2 cc', 3, 'before', '17:00:00', 0, 3, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #4: Retirar Dispositivo intravaginal
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Retirar Dispositivo intravaginal', 2, 'before', '08:00:00', 1, 4, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #5: Inyectar Prostaglandina 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar Prostaglandina 2 cc', 2, 'before', '08:00:00', 0, 5, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #6: Inyectar Cipionato de estradiol 1,5 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar Cipionato de estradiol 1,5 cc', 2, 'before', '08:00:00', 0, 6, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #7: Inyectar Novormon (eCG) 2,5 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar Novormon (eCG) 2,5 cc', 2, 'before', '08:00:00', 0, 7, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #8: Pintura en la base de la cola
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Pintura en la base de la cola', 2, 'before', '08:00:00', 1, 8, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #9: Inseminación Artificial a Tiempo Fijo (IATF)
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inseminación Artificial a Tiempo Fijo (IATF)', 0, 'before', '08:00:00', 1, 9, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #10: Inyectar GnRH 3 cc a vacas sin respuesta a celo (sin repinta
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar GnRH 3 cc a vacas sin respuesta a celo (sin repintar)', 0, 'before', '08:00:00', 1, 10, NOW(), NOW()
WHERE @p_exists = 0;

-- =====================================================================
-- Protocol: Rodeo General
-- =====================================================================
SET @p_exists := (SELECT COUNT(*) FROM protocols WHERE name = 'Rodeo General' AND country_id = @ar_country_id AND vet_id IS NULL);

INSERT INTO protocols (guid, technique_id, country_id, vet_id, created_by_type, created_by_id, name, color, created_at, updated_at)
SELECT UUID(), (SELECT id FROM techniques WHERE name = 'Vacas - Carne' AND parent_id IS NOT NULL), @ar_country_id, NULL, 'superadmin', @super_admin_id, 'Rodeo General', '#92d050', NOW(), NOW()
WHERE @p_exists = 0;

SET @protocol_id := (SELECT id FROM protocols WHERE name = 'Rodeo General' AND country_id = @ar_country_id AND vet_id IS NULL LIMIT 1);

-- Task #0: Colocar Dispositivo intravaginal
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Colocar Dispositivo intravaginal', 10, 'before', '08:00:00', 0, 0, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #1: Inyectar Benzoato de estradiol 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar Benzoato de estradiol 2 cc', 10, 'before', '08:00:00', 0, 1, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #2: Inyectar GnRH 3 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar GnRH 3 cc', 10, 'before', '08:00:00', 0, 2, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #3: Retirar Dispositivo intravaginal
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Retirar Dispositivo intravaginal', 2, 'before', '08:00:00', 1, 3, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #4: Inyectar Prostaglandina 2 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar Prostaglandina 2 cc', 2, 'before', '08:00:00', 0, 4, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #5: Inyectar Cipionato de estradiol 1,5 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar Cipionato de estradiol 1,5 cc', 2, 'before', '08:00:00', 0, 5, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #6: Inyectar Novormon (eCG) 2,5 cc
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar Novormon (eCG) 2,5 cc', 2, 'before', '08:00:00', 0, 6, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #7: Pintura en la base de la cola
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Pintura en la base de la cola', 2, 'before', '08:00:00', 1, 7, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #8: Inseminación Artificial a Tiempo Fijo (IATF)
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inseminación Artificial a Tiempo Fijo (IATF)', 0, 'before', '08:00:00', 1, 8, NOW(), NOW()
WHERE @p_exists = 0;

-- Task #9: Inyectar GnRH 3 cc a vacas sin respuesta a celo (sin repinta
INSERT INTO protocol_tasks (guid, protocol_id, description, days_offset, time_of_day, time, important, sort_order, created_at, updated_at)
SELECT UUID(), @protocol_id, 'Inyectar GnRH 3 cc a vacas sin respuesta a celo (sin repintar)', 0, 'before', '08:00:00', 1, 9, NOW(), NOW()
WHERE @p_exists = 0;

COMMIT;

