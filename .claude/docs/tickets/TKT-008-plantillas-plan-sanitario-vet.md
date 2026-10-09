# TKT-008 - Plantillas de plan sanitario propias del vet

## Tipo
Feature (backend + frontend)

## Contexto
Hoy los planes sanitarios de un establecimiento (`EstablishmentHealthPlan`) solo pueden instanciarse a partir de plantillas globales (`HealthPlanTemplate`) gestionadas por `super-admin`. El vet no tiene forma de armar su propia secuencia de actividades sanitarias reutilizable entre establecimientos/clientes — solo puede usar lo que la plataforma publicó. Esto limita la personalización de protocolos propios de cada práctica veterinaria.

## Estado actual
- `HealthPlanTemplate` (`back/app/Models/HealthPlanTemplate.php`) es 100% global: campos `name`, `health_plan_category_id`, y una relación `belongsToMany` con `HealthActivity` vía pivot `health_plan_template_activity` (`months`, `sort_order`). No tiene ningún campo de ownership/tenant.
- CRUD de plantillas: `AdminHealthPlanTemplateController`, gateado por permisos `health-plan-templates.{read,create,update,delete}`, asignados solo a `super-admin` (`RoleSeeder.php:29`, vía `syncPermissions(Permission::all())`).
- Consumo tenant: `VetHealthPlanTemplateController@index/show`, solo lectura, gateado por `establishment-health-plans.read` (`back/routes/api/establishment-health-plans.php:18-19`). No hay create/update/delete para el tenant.
- Permisos de `EstablishmentHealthPlan` (instancias, no plantillas) por rol: `vet` y `vet-assistant` tienen `read/create/update/confirm`; `vet-administrative` tiene todo menos `confirm` (`EstablishmentHealthPlanPermissionsSeeder.php`).
- `VetMenu.vue` no tiene entrada de "Plantillas" en el panel tenant.

## Decisiones tomadas (no negociables)

### DEC-NEG-01: Ownership exclusivo por vet
Las plantillas creadas por un vet son propiedad exclusiva de ese tenant (`vet_id` no nulo, FK a `vets`). Un vet solo puede ver/editar/borrar sus propias plantillas, nunca las de otro vet. Toda query de listado/detalle debe filtrar por `vet_id = current_vet.id` (regla dura de multi-tenant). Las plantillas globales (`vet_id = null`) siguen siendo visibles en modo lectura para todos los tenants, igual que hoy.

### DEC-NEG-02: Roles con permiso de creación/edición
Solo `vet` y `vet-assistant` pueden crear/editar/borrar plantillas propias. `vet-administrative` mantiene acceso de solo lectura (igual que el resto de las plantillas), sin permisos de escritura sobre plantillas.

### DEC-NEG-03: Contenido de la plantilla limitado al catálogo global de actividades
El vet arma su plantilla combinando `HealthActivity` ya existentes en el catálogo global (elige actividades + define `months`/`sort_order` propios vía el pivot). No puede crear `HealthActivity` nuevas — ese catálogo permanece regulatorio/global (Aftosa, Brucelosis, etc., ligado a especie y cumplimiento SENASA/OIE por país). La misma restricción aplica a `HealthPlanCategory`: el vet selecciona una categoría existente del catálogo global, no crea categorías nuevas.

### DEC-NEG-04: Bloqueo total si la plantilla ya generó un plan instanciado
Si una plantilla de vet ya fue usada para instanciar al menos un `EstablishmentHealthPlan`, queda bloqueada: no se puede editar ni eliminar (bloqueo total, sin versionado ni snapshot). El backend debe rechazar el update/delete con un error explícito indicando el motivo. Esta restricción NO aplica a las plantillas globales (fuera de scope de este ticket — su ciclo de vida no cambia).

## Decisiones que el arquitecto debe tomar

### A definir 1: Modelado de ownership y migración
Definir si `vet_id` va directo en `health_plan_templates` (nullable, `null` = global) o si conviene un modelo/tabla distinta para plantillas de vet. Debe soportarse con una migración que agregue la FK sin romper las plantillas globales existentes (backfill: todas las filas actuales quedan con `vet_id = null`). Confirmar índice compuesto para el scope de tenant.

### A definir 2: Detección de "plantilla en uso" para el bloqueo
Definir cómo se determina que una plantilla "ya generó al menos un `EstablishmentHealthPlan`" — requiere confirmar si `EstablishmentHealthPlan` guarda una FK directa a `health_plan_template_id`/`guid` (verificar modelo) y si hace falta un scope o policy específico (`HealthPlanTemplatePolicy`) para el check antes de update/delete.

### A definir 3: Permisos y rutas
Definir el naming de los nuevos permisos tenant (ej. `establishment-health-plan-templates.{create,update,delete}` o similar, siguiendo la convención existente) y si conviene ampliar `VetHealthPlanTemplateController` con escritura o crear un controlador separado, bajo `routes/api/establishment-health-plans.php` o un archivo propio.

### A definir 4: Separación en listados (propias vs. globales)
Definir si el endpoint de listado tenant devuelve ambos conjuntos combinados con un flag distintivo (`is_global`/`vet_id`) o si conviene exponer dos endpoints/params de filtro separados, para que el frontend pueda mostrar "Mis plantillas" vs "Plantillas del sistema".

### A definir 5: Frontend — nueva sección en panel tenant
Definir estructura del nuevo módulo/página bajo el panel tenant (`VetMenu.vue` no tiene entrada de "Plantillas" hoy) para listar, crear y editar plantillas propias, y cómo se integra con el selector existente de plantillas al instanciar un `EstablishmentHealthPlan` (debe distinguir visualmente propias vs. globales y respetar el bloqueo de edición/borrado en la UI).

## Restricciones
- Regla dura de multi-tenant: toda query sobre `health_plan_templates` con `vet_id` no nulo debe ir scopeada por el tenant autenticado.
- GUID como identificador en rutas y payloads, nunca `id` interno.
- No modificar el ciclo de vida de las plantillas globales (`AdminHealthPlanTemplateController` sigue igual, gestionado por `super-admin`).
- No se permite crear `HealthActivity` ni `HealthPlanCategory` nuevas desde el tenant en este ticket.
- El frontend no inventa el shape del contrato: los campos que distinguen propias/globales y el flag de bloqueo deben venir del Resource backend.

## Investigación previa que el arquitecto debe hacer
1. Confirmar el modelo/migración de `EstablishmentHealthPlan` para saber cómo referencia su `HealthPlanTemplate` de origen (FK/guid) y si ya existe algún campo que ayude a detectar uso.
2. Revisar `AdminHealthPlanTemplateController` y `VetHealthPlanTemplateController` completos para decidir si se extiende el segundo o se crea uno nuevo para escritura tenant.
3. Revisar `EstablishmentHealthPlanPermissionsSeeder.php` y el seeder de permisos de plantillas globales para definir el naming y seeding de los nuevos permisos tenant sin colisionar con los existentes.
4. Revisar el flujo actual del selector de plantillas en el frontend (componente que consume `VetHealthPlanTemplateController@index`) para planificar la integración de "Mis plantillas" vs "Plantillas del sistema" y el bloqueo de edición.
5. Confirmar si existe algún patrón/trait de scope de tenant ya usado en otros modelos tenant-scoped del proyecto, para reutilizarlo en `HealthPlanTemplate`.

## Output esperado
Plan en `.claude/docs/plans/TKT-008-plantillas-plan-sanitario-vet-plan.md`
