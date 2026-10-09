# QA Review — Frontend: TKT-008 Plantillas de plan sanitario propias del vet
Fecha: 2026-09-24
Scope: `front/src/modules/establishment-health-plans/**` (módulo completo, fase 1 + TKT-008), `front/src/components/layouts/partials/VetMenu.vue`, `front/src/modules/health/types/health.types.ts`, `front/src/modules/vets/router/vets-tenant.routes.ts`, `front/src/router/index.ts`. Cruzado contra el backend real (`VetHealthPlanTemplateController`, Resources, Requests, seeder de permisos) para validar el contrato.

## Resumen ejecutivo
- Críticos: 0
- Mayores: 3
- Menores: 0 (2 observaciones adicionales, no bloqueantes)
- Estado: CON OBSERVACIONES

Los tres puntos críticos que pediste auditar específicamente están bien resueltos:
1. **Modo creación vs. edición + bloqueo `is_locked`**: `VetHealthPlanTemplateDrawer.vue` distingue los modos correctamente y no confía solo en el 403 del backend — deshabilita inputs, submit y muestra alerta cuando `is_locked` es `true`, tanto en el drawer como en los botones de la tabla (`VetHealthPlanTemplatesListPage.vue`).
2. **Filtro `scope=own|global|all`**: bien conectado — dos objetos `reactive()` independientes (`ownFilters`/`globalFilters`) con `scope: 'own'`/`scope: 'global'` fijo, cada uno alimentando su propia `useQuery`. La distinción visual propia/global se resuelve con tabs (`Mis plantillas` / `Plantillas del sistema`) en el listado, y con sufijo de label (`— Mi plantilla` / `— Plantilla del sistema`) en el selector de `VetEstablishmentHealthPlanNewPage.vue`, tal como pedía el ticket.
3. **`vetGuid` en catálogos**: todas las queries (`health-activities-catalog`, `health-plan-categories-catalog`, `health-plan-templates-catalog`) leen `vetGuid` desde `computed(() => route.params.vetGuid as string)` — la misma fuente que usa `vetTenantGuard`. No hay hardcodeo ni store paralelo con un vet "en memoria"; si el usuario navega a otro vet, el route param cambia y las queries se invalidan/refetchean solas. Sin riesgo de fuga entre tenants.

Los 3 hallazgos mayores son de **convención de código**, no de lógica de negocio ni seguridad.

## Problemas mayores

### [M-01] — Strings hardcodeados en templates en vez de `$t('clave')`
**Archivos**: prácticamente todo el módulo nuevo, ejemplos puntuales:
- `front/src/modules/establishment-health-plans/pages/tenant/VetHealthPlanTemplatesListPage.vue` líneas 60-61, 65-68, 74, 132
- `front/src/modules/establishment-health-plans/components/tenant/VetHealthPlanTemplateDrawer.vue` líneas 149, 155, 170, 178, 183, 190, 219
- `front/src/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanNewPage.vue` líneas 61 (`'Mi plantilla'`/`'Plantilla del sistema'`), 97, 120-125
- `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlanForm.vue` líneas 51, 63, 82, 91, 98

**Código actual** (ejemplo, `VetHealthPlanTemplatesListPage.vue`):
```vue
<AppHeader
  title="Plantillas de plan sanitario"
  subtitle="Gestioná tus propias plantillas o consultá las del catálogo del sistema."
>
```

**Corrección**:
```vue
<AppHeader
  :title="t('establishmentHealthPlanTemplates.list.title')"
  :subtitle="t('establishmentHealthPlanTemplates.list.subtitle')"
>
```
con `const { t } = useI18n()` en el `<script setup>` y las claves nuevas agregadas en `front/src/i18n/locales/es/` (hoy el directorio solo tiene `auth.ts`, `global.ts`, `support-messages.ts` — no existe ningún namespace para `health`/`establishment-health-plans`, hay que crearlo).

**Nota de contexto**: esto no es una regresión exclusiva de este ticket — verifiqué que `front/src/modules` completo (166 componentes `.vue`) no usa `$t(` en ningún lado; es deuda sistémica del proyecto. Lo marco como Mayor igual porque la convención (`frontend-conventions.md`) lo exige explícitamente y el módulo nuevo la sigue reproduciendo, pero no es algo introducido específicamente por TKT-008.

### [M-03] — Estado de drawer local con `ref()` en vez de UI store del módulo
**Archivo**: `front/src/modules/establishment-health-plans/pages/tenant/VetHealthPlanTemplatesListPage.vue` líneas 21-23

**Código actual**:
```ts
const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const editGuid = ref<string | null>(null)
```

**Corrección**: el módulo ya tiene `stores/establishment-health-plan-ui.store.ts` (hoy solo con filtros de listado). Debería extenderse (o agregarse un store hermano `health-plan-template-ui.store.ts`) para mover ahí el estado del drawer, según la convención: *"Modales abiertos, filtros activos, estado de drawers → Pinia, nunca `ref()` local en el componente"*.
```ts
// stores/establishment-health-plan-ui.store.ts
const templateDrawer = reactive({ open: false, mode: 'create' as 'create' | 'edit', editGuid: null as string | null })
function openTemplateDrawerCreate() { templateDrawer.mode = 'create'; templateDrawer.editGuid = null; templateDrawer.open = true }
function openTemplateDrawerEdit(guid: string) { templateDrawer.mode = 'edit'; templateDrawer.editGuid = guid; templateDrawer.open = true }
```
**Nota de contexto**: el mismo patrón (ref local, sin store) ya existe tal cual en el admin (`front/src/modules/health/pages/HealthPlanTemplatesPage.vue` líneas 25-27) — el plan explícitamente pedía calcar esa estructura. Es deuda heredada, pero como `VetHealthPlanTemplatesListPage.vue` es un archivo 100% nuevo de este ticket, corresponde marcarlo igual.

### [M-04] — HTML crudo (`a-select`) donde existe átomo equivalente (`BaseSelect`)
**Archivo**: `front/src/modules/establishment-health-plans/components/tenant/VetHealthPlanTemplateDrawer.vue` líneas 187-202

**Código actual**:
```vue
<a-select
  v-model:value="health_plan_category_guid"
  v-bind="categoryAttrs"
  placeholder="Seleccioná una categoría"
  :loading="categoriesLoading"
  :disabled="isLocked"
  style="width: 100%"
>
  <a-select-option v-for="cat in allCategories ?? []" :key="cat.guid" :value="cat.guid">
    {{ cat.name }}
  </a-select-option>
</a-select>
```

**Corrección**: `BaseSelect` ya existe y ya se usa en el resto del mismo módulo (`EstablishmentHealthPlanForm.vue`, `VetEstablishmentHealthPlansListPage.vue`) con la forma `:options="[{ value, label }]"`.
```vue
<BaseSelect
  v-model="health_plan_category_guid"
  :options="(allCategories ?? []).map(c => ({ value: c.guid, label: c.name }))"
  :loading="categoriesLoading"
  :disabled="isLocked"
  placeholder="Seleccioná una categoría"
/>
```
**Nota de contexto**: mismo caso que M-03 — es un calco exacto de `front/src/modules/health/components/HealthPlanTemplateDrawer.vue` (líneas 79-95 aprox., mismo `a-select` crudo), que el plan pidió reutilizar como estructura base. No es una regresión nueva, pero el checklist lo marca Mayor en ambos archivos.

## Problemas menores
Ninguno detectado dentro del checklist oficial (`m-01` a `m-08`): `<script setup lang="ts">` presente en todos los archivos, `defineProps`/`defineEmits` siempre con genéricos tipados, composables con prefijo `use`, ningún `any`.

### Observaciones adicionales (no forman parte del checklist oficial)
1. **Eliminar sin confirmación**: `VetHealthPlanTemplatesListPage.vue::onDeleteConfirm` (línea 39-41) dispara `mutateDelete(item.guid)` directo al click, sin `BaseConfirmDialog` (que sí se usa para cancelar un plan en `EstablishmentHealthPlanCancelModal.vue`). Es una acción destructiva sin paso de confirmación en la UI. De nuevo, calca el mismo patrón del admin (`HealthPlanTemplatesPage.vue`), así que es deuda compartida, no exclusiva de este ticket — vale la pena resolverla en ambos lugares a la vez.
2. **Naming engañoso en payload/schema (no es violación C-05 real)**: `CreateEstablishmentHealthPlanPayload.establishment_id` / `.health_plan_template_id` (`establishment-health-plan.types.ts` y su validator Zod) están nombrados como si fueran ids numéricos, pero el Zod schema los valida con `.uuid(...)` y el backend (`StoreEstablishmentHealthPlanRequest`) los valida `exists:...,guid` — o sea que en la práctica siempre viajan GUIDs, solo el nombre del campo es confuso. Esto es del contrato Phase 1 (`StoreEstablishmentHealthPlanRequest.php`), preexistente y fuera del alcance de TKT-008; el frontend hace bien en respetar el contrato del backend tal cual está (regla "la API es fuente de verdad").

## Verificaciones cruzadas
- **Types vs Resource backend**: OK. `HealthPlanTemplate`/`HealthPlanTemplateListItem`/`HealthPlanTemplateListParams` (`front/src/modules/health/types/health.types.ts`) matchean campo a campo con `HealthPlanTemplateResource`/`HealthPlanTemplateListResource`/`IndexHealthPlanTemplateRequest` (incluyendo `vet_guid`, `is_own`, `is_locked`, `scope`).
- **Schema Zod vs FormRequest backend**: OK. `healthPlanTemplateSchema` (`name`, `health_plan_category_guid` uuid, `activities[].health_activity_guid` uuid, `activities[].months` 1-12) matchea `StoreHealthPlanTemplateRequest::rules()`/`UpdateHealthPlanTemplateRequest` exactamente.
- **Rutas registradas en router/index.ts**: OK. `vet-health-plan-templates-list` se registra vía `vetEstablishmentHealthPlansRoutes` → `vetsTenantRoutes` → `front/src/router/index.ts` (línea 44-47), heredando `authGuard` (nivel padre) y `vetTenantGuard` (nivel `vetsTenantRoutes`). Lazy loading correcto (`() => import(...)`).
- **Claves i18n completas**: PROBLEMA — no aplica en sentido estricto porque no se usa `$t()` en absoluto en el módulo (ver M-01); no hay ninguna clave nueva registrada en `front/src/i18n/locales/es/`.
- **Query invalidation por cada mutation**: OK. Las 3 mutaciones nuevas (`useCreateHealthPlanTemplateCatalog`, `useUpdateHealthPlanTemplateCatalog`, `useDeleteHealthPlanTemplateCatalog`) invalidan `['health-plan-templates-catalog', vetGuid]` (y update también invalida el detalle individual).
- **PermissionGuard en acciones de escritura**: OK. Botón "Nueva plantilla" (`establishment-health-plans.templates.create`), "Editar" (`.templates.update`), "Eliminar" (`.templates.delete`) — nombres de permiso verificados contra `routes/api/establishment-health-plans.php` y `EstablishmentHealthPlanPermissionsSeeder.php`, coinciden exactamente y `vet-administrative` queda excluido (DEC-NEG-02).

## Verificación técnica
`cd front && npm run type-check` → **OK, sin errores** (`vue-tsc --noEmit` terminó sin output).

## Archivos revisados
- `front/src/modules/establishment-health-plans/api/health-plan-templates-catalog.api.ts`
- `front/src/modules/establishment-health-plans/api/health-activities-catalog.api.ts`
- `front/src/modules/establishment-health-plans/api/health-plan-categories-catalog.api.ts`
- `front/src/modules/establishment-health-plans/api/establishment-health-plans.api.ts`
- `front/src/modules/establishment-health-plans/composables/useHealthPlanTemplateCatalog.ts`
- `front/src/modules/establishment-health-plans/composables/useHealthPlanTemplateCatalogMutations.ts`
- `front/src/modules/establishment-health-plans/composables/useEstablishmentHealthPlanDetail.ts`
- `front/src/modules/establishment-health-plans/composables/useEstablishmentHealthPlanList.ts`
- `front/src/modules/establishment-health-plans/composables/useEstablishmentHealthPlanMutations.ts`
- `front/src/modules/establishment-health-plans/components/tenant/VetHealthPlanTemplateDrawer.vue`
- `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlanForm.vue`
- `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlansTable.vue`
- `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlanCalendar.vue`
- `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlanCancelModal.vue`
- `front/src/modules/establishment-health-plans/pages/tenant/VetHealthPlanTemplatesListPage.vue`
- `front/src/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanNewPage.vue`
- `front/src/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlansListPage.vue`
- `front/src/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanDetailPage.vue`
- `front/src/modules/establishment-health-plans/router/vet-establishment-health-plans.routes.ts`
- `front/src/modules/establishment-health-plans/stores/establishment-health-plan-ui.store.ts`
- `front/src/modules/establishment-health-plans/types/establishment-health-plan.types.ts`
- `front/src/modules/establishment-health-plans/validators/establishment-health-plan.validator.ts`
- `front/src/modules/establishment-health-plans/constants/establishment-health-plan.constants.ts`
- `front/src/components/layouts/partials/VetMenu.vue`
- `front/src/modules/health/types/health.types.ts`
- `front/src/modules/vets/router/vets-tenant.routes.ts`
- `front/src/router/index.ts`
- `front/src/modules/health/validators/health-plan-template.validator.ts` (referencia, reuso cruzado)
- `front/src/modules/health/components/ActivityMonthMatrix.vue` (referencia, reuso cruzado)
- `front/src/modules/health/pages/HealthPlanTemplatesPage.vue` (referencia, para contrastar precedente admin)
- `front/src/modules/health/components/HealthPlanTemplateDrawer.vue` (referencia, para contrastar precedente admin)

### Backend consultado para cross-check (no es el foco de la revisión, solo contrato)
- `back/app/Http/Controllers/V1/VetHealthPlanTemplateController.php`
- `back/app/Http/Resources/V1/HealthPlanTemplateResource.php`, `HealthPlanTemplateListResource.php`
- `back/app/Http/Requests/Health/{Store,Update,Index}HealthPlanTemplateRequest.php`
- `back/app/Http/Requests/EstablishmentHealthPlans/StoreEstablishmentHealthPlanRequest.php`
- `back/routes/api/establishment-health-plans.php`
- `back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php`
- `back/app/Repositories/HealthPlanTemplateRepositoryEloquent.php`
