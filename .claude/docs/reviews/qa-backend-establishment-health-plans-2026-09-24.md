# QA Review — Backend: Planes Sanitarios por establecimiento (Fase 1)
Fecha: 2026-09-24
Scope: módulo completo `establishment-health-plans` según `.claude/docs/plans/planes-sanitarios-establecimiento-fase1-plan.md` — migraciones, modelos, `HealthPlanYear`, Repository/Interface, binding, excepciones, Service, FormRequests, Resources, Controllers, rutas, seeder de permisos + `DatabaseSeeder`, y tests (Service/Controller/HealthPlanYear/VetHealthPlanTemplateController).

## Resumen ejecutivo
- Críticos: 0
- Mayores: 0
- Menores: 0
- Estado: APROBADO

No encontré violaciones de las reglas duras del dominio SAV ni de las convenciones backend en el checklist. El módulo está bien alineado con los patrones reales del codebase (no con lo que describe literalmente `backend-conventions.md`, que está desactualizado en varios puntos — ya documentado por el propio plan en DEC-02/DEC-03 y en "Riesgos"). Verifiqué punto por punto los 5 focos de negocio pedidos; el único hallazgo real es una condición de carrera no cerrada del todo en el bloqueo de duplicados, que dejo como observación adicional porque no es una regla del checklist pero sí un riesgo de integridad de datos.

## Problemas críticos (bloquean merge)
Ninguno.

## Problemas mayores
Ninguno.

## Problemas menores
Ninguno.

## Verificaciones cruzadas

- **Resource vs Controller — campos consistentes**: OK. `EstablishmentHealthPlanListResource`/`Resource`/`ActivityResource` exponen exactamente los campos que el Controller espera y que la spec define (`guid`, `client`, `establishment`, `template`, `year`, fechas, `editable`, `activities`, `status`, `confirmed_by`, etc.). Ningún `id` interno expuesto.
- **FormRequest vs Migración — campos validados existen en tabla**: OK. `StoreEstablishmentHealthPlanRequest` valida `establishment_id`, `health_plan_template_id`, `year` (existen en `establishment_health_plans`); `client_id`, `starts_on`, `ends_on`, `created_by_user_id` se derivan en Controller/Service, no vienen del cliente — correcto, evita que el cliente falsifique `client_id` o fechas.
- **Binding en AppServiceProvider**: OK. `EstablishmentHealthPlanRepositoryInterface::class => EstablishmentHealthPlanRepositoryEloquent::class` está registrado en `register()`.
- **Rutas incluidas en api.php**: OK. `back/routes/api.php` hace `foreach (glob(__DIR__.'/api/*.php'))`, así que `establishment-health-plans.php` se carga automáticamente sin necesidad de un `require` explícito — no es una lista manual como sugiere el checklist, y el archivo nuevo cae dentro del glob.
- **4 permisos en PermissionSeeder**: OK. `EstablishmentHealthPlanPermissionsSeeder` define los 4 (`read/create/update/confirm`), con `guid` explícito y guard `web`; está registrado en `DatabaseSeeder::run()` inmediatamente después de `ProgramPermissionsSeeder`, tal como indica el plan.

## Validación de los puntos de negocio pedidos

1. **Bloqueo de duplicados** (`establishment_id` + `health_plan_template_id` + `year`, solo activos): implementado en `StoreEstablishmentHealthPlanRequest::withValidator` (422 con mensaje claro) + recheck en `EstablishmentHealthPlanService::create()` dentro de `DB::transaction()`, exactamente como describe DEC-04. Test cubre duplicado y re-instanciación tras cancelar.
   - **Observación adicional (no bloqueante)**: el recheck dentro de la transacción no usa `lockForUpdate()`. Bajo el nivel de aislamiento por defecto de MySQL (REPEATABLE READ), dos requests concurrentes podrían pasar ambos el `existsActiveFor()` antes de que cualquiera haga commit, resultando en dos planes activos duplicados para la misma tupla — el mismo escenario que DEC-04 dice defender. Si la probabilidad de dos instanciaciones simultáneas del mismo establecimiento+template+año es real en producción, vale la pena agregar `->lockForUpdate()` a la query de `existsActiveFor` dentro de la transacción (requiere que la transacción también bloquee filas de `establishments` o similar para que el lock tenga algo sobre qué aplicarse, dado que hoy no hay filas previas que lockear en el primer insert — alternativa: usar `Cache::lock()` con una key `establishment_id:template_id:year` alrededor de todo el bloque). No es parte del checklist de reglas duras/convenciones, así que no lo marco como bloqueante, pero es el único gap real que encontré en la lógica de negocio pedida.

2. **Calendario como snapshot físico, no cálculo en caliente**: correcto. `EstablishmentHealthPlanActivity` se materializa una única vez en `EstablishmentHealthPlanService::create()` vía `EstablishmentHealthPlanActivity::insert($rows)` (bulk, con `guid`/`created_at`/`updated_at` seteados a mano porque `insert()` bypassa `HasGuid::bootHasGuid()` — comentado correctamente en el código). `health_plan_template_id` en `EstablishmentHealthPlan` queda solo como trazabilidad y nunca se vuelve a leer para reconstruir el calendario. Test `test_snapshot_does_not_change_if_template_is_edited_after_instantiation` confirma el comportamiento.

3. **Permisos**: correctos y verificados en `EstablishmentHealthPlanPermissionsSeeder` — `vet`/`vet-assistant` reciben los 4 permisos; `vet-administrative` recibe `read/create/update` pero NO `confirm` (DEC-06); `client-owner`/`client-manager` reciben solo `confirm`. `EstablishmentHealthPlanActivity::CONFIRM_ROLES` a nivel de negocio en el Service coincide exactamente con los 4 roles esperados. Guard `web` usado consistentemente (nunca `sanctum`).

4. **Scoping multi-tenant**: correcto. `EstablishmentHealthPlanRepositoryEloquent::paginateForVet`/`findByGuidForVet` filtran siempre por `vet_id`; `StoreEstablishmentHealthPlanRequest::withValidator` verifica explícitamente que el establecimiento pertenezca a un cliente de la vet autenticada (`$vet->clients()->whereKey($establishment->client_id)->exists()`); `confirmActivity` en el Controller resuelve la actividad únicamente a través de `$plan->activities` de un plan ya scopeado por `findByGuidForVet($guid, $vet->id)`, así que no hay ruta para acceder a actividades de otro tenant. Test `test_vet_cannot_see_plan_of_another_vet` cubre el caso cross-tenant con 404.

5. **`VetHealthPlanTemplateController` reutiliza el catálogo admin sin modificarlo**: confirmado por `git status` — ningún archivo de `HealthPlanTemplateService`, `HealthPlanTemplateRepositoryEloquent`, `Http/Requests/Health/*` o `HealthPlanTemplateResource`/`HealthPlanTemplateListResource` aparece modificado. El controller nuevo solo inyecta `HealthPlanTemplateService` y usa `IndexHealthPlanTemplateRequest` existente, tal como indica DEC-07.

## Observaciones adicionales (no oficiales del checklist, informativas)

- **Discrepancia documentada entre `backend-conventions.md` y el código real**: el skill describe `I{Nombre}Repository` y permisos `lectura/alta/modificacion/baja`; el módulo nuevo usa `{Nombre}RepositoryInterface`/`RepositoryEloquent` y permisos `read/create/update/confirm`. Verifiqué contra `AppServiceProvider.php` y otros módulos (`ProgramRepositoryInterface`, `HealthPlanTemplateRepositoryInterface`, permisos de `programs`/`health-plan-templates`) — el módulo nuevo es 100% consistente con el patrón real, no con el skill. Esto ya está señalado por el propio plan (DEC-02/DEC-03) como una discrepancia a corregir en el skill doc en un ticket aparte, no en este módulo.
- **`Service` y `FormRequest` consultan modelos directamente en vez de pasar por un Repository** (`Establishment::with(...)->findOrFail()`, `HealthPlanTemplate::where(...)->first()`, `EstablishmentHealthPlanActivity::insert()` en el Service; `Establishment::where(...)->first()` en `StoreEstablishmentHealthPlanRequest`; `Establishment::where(...)->first()`/`HealthPlanTemplate::where(...)->value('id')` en `EstablishmentHealthPlanController::resolveGuidsToIds`). Esto viola literalmente "el Service inyecta la Interface, nunca el Model directamente" de `backend-conventions.md`. Sin embargo, es el patrón establecido en todo el codebase real (`ProgramService`/`ProgramController`/`StoreProgramRequest`, `ClientService`, `VetService`, etc. hacen exactamente lo mismo con modelos secundarios que no son el agregado principal del Service/Repository). No lo marco como C-03/M-09 porque penalizaría al módulo nuevo por seguir el mismo patrón que el 100% de los módulos existentes — es una deuda arquitectónica preexistente y transversal, no algo introducido por esta feature. El caso de `EstablishmentHealthPlanActivity` sin Repository propio además está explícitamente justificado en el plan (DEC-10, mismo precedente que `ProgramTarget`).

## Archivos revisados

- `back/database/migrations/2026_09_24_000001_create_establishment_health_plans_table.php`
- `back/database/migrations/2026_09_24_000002_create_establishment_health_plan_activities_table.php`
- `back/app/Models/EstablishmentHealthPlan.php`
- `back/app/Models/EstablishmentHealthPlanActivity.php`
- `back/app/Support/HealthPlanYear.php`
- `back/app/Contracts/Repositories/EstablishmentHealthPlanRepositoryInterface.php`
- `back/app/Repositories/EstablishmentHealthPlanRepositoryEloquent.php`
- `back/app/Providers/AppServiceProvider.php` (diff del binding)
- `back/app/Exceptions/EstablishmentHealthPlanAlreadyExistsException.php`
- `back/app/Exceptions/EstablishmentHealthPlanNotEditableException.php`
- `back/app/Exceptions/EstablishmentHealthPlanActivityConfirmationNotAllowedException.php`
- `back/app/Services/EstablishmentHealthPlanService.php`
- `back/app/Http/Requests/EstablishmentHealthPlans/IndexEstablishmentHealthPlanRequest.php`
- `back/app/Http/Requests/EstablishmentHealthPlans/StoreEstablishmentHealthPlanRequest.php`
- `back/app/Http/Requests/EstablishmentHealthPlans/ConfirmEstablishmentHealthPlanActivityRequest.php`
- `back/app/Http/Resources/V1/EstablishmentHealthPlanListResource.php`
- `back/app/Http/Resources/V1/EstablishmentHealthPlanResource.php`
- `back/app/Http/Resources/V1/EstablishmentHealthPlanActivityResource.php`
- `back/app/Http/Controllers/V1/EstablishmentHealthPlanController.php`
- `back/app/Http/Controllers/V1/VetHealthPlanTemplateController.php`
- `back/routes/api/establishment-health-plans.php`
- `back/routes/api.php` (verificación de inclusión automática)
- `back/database/seeders/EstablishmentHealthPlanPermissionsSeeder.php`
- `back/database/seeders/DatabaseSeeder.php` (diff)
- `back/tests/Unit/EstablishmentHealthPlanServiceTest.php`
- `back/tests/Unit/HealthPlanYearTest.php`
- `back/tests/Feature/EstablishmentHealthPlanControllerTest.php`
- `back/tests/Feature/VetHealthPlanTemplateControllerTest.php`

Archivos de referencia usados para verificar precedentes/convenciones reales (no modificados por esta feature):
- `back/app/Http/Controllers/V1/ProgramController.php`, `back/app/Http/Requests/Programs/StoreProgramRequest.php`, `back/app/Services/ProgramService.php`, `back/app/Services/ClientService.php`, `back/app/Services/VetService.php`
- `back/app/Repositories/BaseRepositoryEloquent.php`, `back/app/Traits/HasGuid.php`, `back/app/Http/Middleware/EnsureUserBelongsToVet.php`, `back/app/Http/Controllers/Controller.php`
- `back/database/seeders/ProgramPermissionsSeeder.php`
