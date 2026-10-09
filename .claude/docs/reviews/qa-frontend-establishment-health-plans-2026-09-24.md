# QA Review — Frontend: establishment-health-plans (Fase 1)
Fecha: 2026-09-24
Scope: `front/src/modules/establishment-health-plans/` completo (api/, components/tenant/, composables/, pages/tenant/, router/, stores/, types/, validators/) + diff en `front/src/modules/vets/router/vets-tenant.routes.ts`

## Resumen ejecutivo
- Críticos: 0
- Mayores: 1
- Menores: 1
- Estado: CON OBSERVACIONES

El módulo espeja fielmente el patrón de `programs/`: feature-module con las 8 carpetas obligatorias, server state 100% en Vue Query, UI state en Pinia, composables tipados con prefijo `use`, validación Zod que coincide campo a campo con el `StoreEstablishmentHealthPlanRequest` del backend, GUID como identificador en toda llamada HTTP, y `PermissionGuard` en todas las acciones de escritura/confirmación. No se encontró ningún endpoint inventado, ningún `id` numérico en URL/payload, ningún `any`, y no hay rastro de alertas automáticas de Fase 2 (solo confirmación manual vía `require_confirmation`/`confirmed_at`/`confirmed_by`, que coincide con la regla dura #7 del dominio).

## Problemas mayores

### [M-01] — Strings hardcodeados en templates en vez de `$t('clave')`
**Archivos**: los 7 componentes/páginas `.vue` del módulo tienen texto en español embebido directamente en el template. Ejemplos:

`front/src/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlansListPage.vue` línea 53:
```
<AppHeader title="Planes Sanitarios" subtitle="Planes sanitarios instanciados por establecimiento.">
```

`front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlanCancelModal.vue` línea 20:
```
`¿Estás seguro de que querés cancelar el plan sanitario de ${props.plan.client.name} en ${props.plan.establishment.name}? Esta acción no se puede deshacer.`
```

**Corrección**: usar `useI18n()` + `$t('establishmentHealthPlans.list.title')`, etc., con las claves registradas en `front/src/i18n/locales/es/`.

**Nota de contexto**: verifiqué que `programs/` (el módulo de referencia que este espeja) tiene exactamente el mismo patrón — cero usos de `$t()` en sus componentes/páginas, y no existe ningún archivo `front/src/i18n/locales/es/programs.ts` ni `establishment-health-plans.ts`. Los únicos módulos con locale propio son `auth`, `global` y `support-messages`. Esto confirma que `establishment-health-plans` replicó fielmente el patrón existente en el codebase, no que introdujo una regresión aislada. Igual lo marco como Mayor porque la convención (`frontend-conventions.md`) lo exige explícitamente — pero la corrección real es un esfuerzo transversal (migrar `programs` y este módulo juntos), no algo a resolver solo acá.

## Problemas menores

### Filtro `establishment_guid` muerto en el UI store
**Archivo**: `front/src/modules/establishment-health-plans/stores/establishment-health-plan-ui.store.ts` líneas 6, 15, 23
```ts
interface EstablishmentHealthPlanFilters {
  client_guid?: string
  establishment_guid?: string   // declarado y reseteado, nunca leído
  ...
}
```
Ningún componente lo setea ni lo lee — `VetEstablishmentHealthPlansListPage.vue` solo mapea `client_id` en `listParams` y no incluye `establishment_id` pese a que el tipo `EstablishmentHealthPlanListParams` y el backend (`IndexEstablishmentHealthPlanRequest` / controller `index`) sí lo soportan.

**Corrección**: si el filtro por establecimiento no es parte del alcance de Fase 1, eliminar el campo `establishment_guid` del store para no dejar estado muerto. Si sí es parte del alcance, agregar el `BaseSelect` de establecimiento en el toolbar y mapearlo a `establishment_id` en `listParams`, igual que se hizo con `client_id`.

## Verificaciones cruzadas

- **Types vs Resource backend**: OK. `EstablishmentHealthPlanListItem`/`Detail`/`Activity` coinciden campo a campo con `EstablishmentHealthPlanListResource`, `EstablishmentHealthPlanResource` y `EstablishmentHealthPlanActivityResource`. `HealthPlanTemplate` (en `health.types.ts`, reutilizado por este módulo) coincide con `HealthPlanTemplateResource`/`HealthPlanTemplateActivityResource` (incluye `months: number[]`).
- **Schema Zod vs FormRequest backend**: OK. `establishmentHealthPlanSchema` (uuid + uuid + year int 2020..año+1) es un espejo exacto de `StoreEstablishmentHealthPlanRequest::rules()`.
- **Rutas registradas en router/index.ts**: OK. `vetEstablishmentHealthPlansRoutes` se importa y spreadea en `front/src/modules/vets/router/vets-tenant.routes.ts` dentro de los children de `/vets/:vetGuid`, protegido por `vetTenantGuard` a nivel de layout — mismo patrón que `vetProgramsRoutes`/`vetProtocolsRoutes` (no hay `beforeEnter` de auth por-ruta en ningún módulo de este codebase; el guard vive en el layout padre + `meta.requiresAuth`, así que esto NO es una desviación).
- **Claves i18n completas**: N/A — el módulo no usa `$t()` en absoluto (ver M-01); no aplica verificar completitud de claves porque no se declaró ninguna.
- **Query invalidation por cada mutation**: OK. Las 4 mutations (`create`, `cancel`, `confirmActivity` y el wrapper de cancelación con modal) invalidan las query keys correctas (`establishment-health-plans`, `establishment-health-plan`) exactamente igual que el patrón de `useProgramMutations.ts`.
- **PermissionGuard en acciones de escritura**: OK. `establishment-health-plans.create` (botón "Nuevo plan" y CTA de empty state), `establishment-health-plans.update` (cancelar plan, en tabla y en detalle), `establishment-health-plans.confirm` (confirmar actividad en el calendario). Coinciden exactamente con los permisos sembrados en `back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php` y con los middlewares `can:` de `back/routes/api/establishment-health-plans.php`.
- **Endpoint del catálogo de templates (`VetHealthPlanTemplateController`)**: OK. `health-plan-templates-catalog.api.ts` llama `GET /v1/vets/{vetGuid}/health-plan-templates` y `GET /v1/vets/{vetGuid}/health-plan-templates/{guid}`, que existen tal cual en `back/routes/api/establishment-health-plans.php` líneas 17-19. El formulario de alta (`EstablishmentHealthPlanNewPage.vue`) usa `useHealthPlanTemplateCatalog` para poblar el select y `useHealthPlanTemplateCatalogDetail` para previsualizar las actividades de la plantilla elegida antes de instanciar — cumple el requisito del ticket.
- **Ausencia de alertas automáticas (Fase 2)**: OK. No hay ningún composable, store ni componente relacionado con scheduling/disparo de alertas; el único mecanismo presente es la confirmación manual (`require_confirmation`, `confirmed_at`, `confirmed_by`, botón "Confirmar" en `EstablishmentHealthPlanCalendar.vue` + `useConfirmEstablishmentHealthPlanActivity`), consistente con la regla dura #7 de `sav-domain-rules.md`.
- **`npm run type-check`**: OK, 0 errores (`vue-tsc --noEmit`, exit code 0). No se reprodujeron los ~40 errores preexistentes de `npm run build` reportados en `main`; ninguno de ellos pertenece a este módulo (`type-check` no señaló nada en `establishment-health-plans/`).

## Archivos revisados
- `front/src/modules/establishment-health-plans/types/establishment-health-plan.types.ts`
- `front/src/modules/establishment-health-plans/api/establishment-health-plans.api.ts`
- `front/src/modules/establishment-health-plans/api/health-plan-templates-catalog.api.ts`
- `front/src/modules/establishment-health-plans/validators/establishment-health-plan.validator.ts`
- `front/src/modules/establishment-health-plans/stores/establishment-health-plan-ui.store.ts`
- `front/src/modules/establishment-health-plans/composables/useEstablishmentHealthPlanList.ts`
- `front/src/modules/establishment-health-plans/composables/useEstablishmentHealthPlanDetail.ts`
- `front/src/modules/establishment-health-plans/composables/useEstablishmentHealthPlanMutations.ts`
- `front/src/modules/establishment-health-plans/composables/useHealthPlanTemplateCatalog.ts`
- `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlansTable.vue`
- `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlanForm.vue`
- `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlanCalendar.vue`
- `front/src/modules/establishment-health-plans/components/tenant/EstablishmentHealthPlanCancelModal.vue`
- `front/src/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlansListPage.vue`
- `front/src/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanNewPage.vue`
- `front/src/modules/establishment-health-plans/pages/tenant/VetEstablishmentHealthPlanDetailPage.vue`
- `front/src/modules/establishment-health-plans/router/vet-establishment-health-plans.routes.ts`
- `front/src/modules/vets/router/vets-tenant.routes.ts` (diff)

### Archivos de referencia consultados (contraste de patrón)
- `front/src/modules/programs/api/program.api.ts`, `composables/useProgramList.ts`, `composables/useProgramMutations.ts`, `router/vet-programs.routes.ts`

### Archivos backend consultados (cross-check de contrato)
- `back/routes/api/establishment-health-plans.php`
- `back/app/Http/Controllers/V1/EstablishmentHealthPlanController.php`
- `back/app/Http/Controllers/V1/VetHealthPlanTemplateController.php`
- `back/app/Http/Requests/EstablishmentHealthPlans/StoreEstablishmentHealthPlanRequest.php`
- `back/app/Http/Resources/V1/EstablishmentHealthPlanListResource.php`, `EstablishmentHealthPlanResource.php`, `EstablishmentHealthPlanActivityResource.php`, `HealthPlanTemplateListResource.php`, `HealthPlanTemplateResource.php`, `HealthPlanTemplateActivityResource.php`
- `back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php`
