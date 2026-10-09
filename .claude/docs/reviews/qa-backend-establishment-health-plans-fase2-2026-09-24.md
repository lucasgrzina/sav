# QA Review — Backend: Planes Sanitarios por establecimiento — Fase 2 (alertas)
Fecha: 2026-09-24
Scope: conexión de `EstablishmentHealthPlan` al motor de alertas (`AlertType::HealthPlanMonth`), según `.claude/docs/plans/planes-sanitarios-alertas-fase2-plan.md`. Archivos nuevos (eventos, listeners, builder, payload DTO) + diffs de archivos modificados (contrato `AlertMessageBuilder`, `DeliverAlertJob`, `NotificationServiceProvider`, `WhatsappTemplateCatalog`, `config/notifications.php`, `TwilioCreateTemplatesCommand`, `UserProfileRepository*`, `EstablishmentHealthPlanService`, `AppServiceProvider`) + tests nuevos/modificados.

## Resumen ejecutivo
- Críticos: 0
- Mayores: 0
- Menores: 0
- Estado: **APROBADO**

No encontré violaciones de las reglas duras del dominio SAV ni de las convenciones de arquitectura backend en ninguno de los archivos revisados. El código sigue con precisión los patrones ya establecidos en el módulo `Program`/`Notifications` (mismo esqueleto de eventos, mismo criterio de borrado de alertas pendientes, mismo patrón de repositorio para destinatarios por rol).

## Problemas críticos (bloquean merge)
Ninguno.

## Problemas mayores
Ninguno.

## Problemas menores
Ninguno.

## Puntos de negocio verificados específicamente

1. **Una sola alerta `HealthPlanMonth` agrupada por mes, destinatarios solo rol `vet`** — OK.
   `GenerateHealthPlanMonthAlertsListener::handle()` agrupa `$plan->activities->pluck('month')->unique()` y crea una `Alert` por mes distinto (no por actividad, DU2-01). Los destinatarios vienen de `UserProfileRepositoryEloquent::listByRoleForVet($plan->vet, 'vet')`, que filtra `whereHas('role', ...'vet')` + `authenticatable_type='vet'` + `authenticatable_id=$vet->id` — solo rol `vet` del tenant correcto. Cubierto por `GenerateHealthPlanMonthAlertsListenerTest::test_recipients_are_only_profiles_with_the_vet_role` (incluye perfiles `vet-assistant`/`client-owner` en el mismo tenant y verifica que NO reciben `AlertRecipient`).

2. **Contrato `AlertMessageBuilder::build(): ?MessageContent` no rompe los 4 builders existentes** — OK.
   Verificado que `ProgramTaskDueMessageBuilder`, `ProgramCreatedMessageBuilder`, `ProgramCancelledMessageBuilder` y `ProgramPdfShareMessageBuilder` siguen declarando `: MessageContent` (no-nullable) sin tocarlos — es un subtipo covariante válido de `?MessageContent` en PHP. `HealthPlanMonthMessageBuilder` es el único que declara `?MessageContent`.

3. **`HealthPlanMonthMessageBuilder` recalcula en destino, no usa el payload congelado** — OK.
   `build()` consulta `$plan->activities()->where('month', $payload->month)->whereNull('confirmed_at')->get()` contra la base en el momento del envío (no una lista guardada en `Alert.payload`, que solo contiene `{"month": N}` por diseño — DEC2-03). Retorna `null` si `$pendingActivities->isEmpty()`. `DeliverAlertJob::handle()` trata ese `null` como `DeliveryStatus::Suppressed` / `failure_reason='no_longer_applicable'`, sin invocar gateway ni fallback — confirmado en código (línea 69-76 de `DeliverAlertJob.php`) y en `DeliverAlertJobTest::test_a_null_content_from_the_builder_suppresses_the_recipient_without_calling_the_gateway` + el feature end-to-end `EstablishmentHealthPlanAlertsFeatureTest::test_confirming_all_activities_before_the_send_time_suppresses_the_delivery`.

4. **Cancelar el plan borra alertas `pending` de meses futuros, sin fugas de alertas huérfanas** — OK.
   `CancelHealthPlanMonthAlertsListener` filtra `type=HealthPlanMonth`, `subject_type='establishment_health_plan'`, `subject_id=$plan->id`, `status='pending'` y hace hard delete (mismo criterio que `HandleProgramCancelledListener`). Como toda `Alert HealthPlanMonth` en `pending` es por construcción de un mes futuro (el listener de generación descarta silenciosamente las fechas pasadas — RF-01 AC#3), el filtro `status=pending` ya equivale a "mes futuro"; no hace falta un filtro adicional por fecha. Cubierto por `CancelHealthPlanMonthAlertsListenerTest` en sus tres variantes: borra las del plan cancelado, no toca alertas de otro plan, no toca alertas ya `dispatched`.

5. **Subject polimórfico correcto** — OK.
   `Alert.subject_type` resuelve a `EstablishmentHealthPlan::class` vía la entrada nueva `'establishment_health_plan' => EstablishmentHealthPlan::class` en `Relation::morphMap()` (`AppServiceProvider::boot()`). El listener asocia `$alert->subject()->associate($plan)` — el plan completo, nunca una `EstablishmentHealthPlanActivity` individual. Confirmado en `GenerateHealthPlanMonthAlertsListenerTest::test_creates_one_alert_per_distinct_month_with_activities` (`assertSame('establishment_health_plan', $alert->subject_type)`).

6. **Eventos disparados DENTRO de la transacción del Service** — Intencional, no es una inconsistencia.
   `EstablishmentHealthPlanService::create()` y `::cancel()` disparan `event(new EstablishmentHealthPlanInstantiatedEvent($plan))` / `event(new EstablishmentHealthPlanCancelledEvent($plan))` dentro del `DB::transaction()`, con listeners síncronos (sin `ShouldQueue`). Verifiqué el precedente exacto en `ProgramService::create()`/`::update()`/`::cancel()` (`back/app/Services/ProgramService.php` líneas 49-123): dispara `ProgramTargetsChangedEvent`/`ProgramCancelledEvent` con el mismo patrón, dentro de la misma transacción. Si el listener lanza una excepción (ej. `HealthPlanYear::dateForMonth()` con `country` nulo), la transacción completa hace rollback — comportamiento correcto y buscado: no debe quedar un `EstablishmentHealthPlan` creado sin sus alertas, ni viceversa. Es la misma garantía de atomicidad que ya tiene `Program`. El propio plan documenta esta dependencia implícita en "Riesgos" (`$plan->client->country` no nulo) como deuda ya mitigada por la validación de Fase 1.

7. **Scoping multi-tenant en `listByRoleForVet()`** — OK.
   `UserProfileRepositoryEloquent::listByRoleForVet()` filtra `where('authenticatable_type', 'vet')->where('authenticatable_id', $vet->id)` además del rol — mismo patrón que `listOwnersForClient()` (regla dura #4). No hay fuga entre tenants.

## Otras verificaciones

- **Registro de listeners**: no hay `EventServiceProvider` en el proyecto (Laravel 12, event discovery automático activado por defecto); `GenerateProgramTaskDueAlertsListener`/`HandleProgramCancelledListener` ya funcionan sin registro explícito, así que `GenerateHealthPlanMonthAlertsListener`/`CancelHealthPlanMonthAlertsListener` (mismo namespace `App\Listeners`, mismo type-hint del evento en `handle()`) quedan auto-descubiertos por el mismo mecanismo. No es una omisión.
- **`Alert`/`AlertRecipient` creados con `new Alert(...)`/`Alert::query()` directo en los listeners, sin Repository**: no es una violación de C-03 — es el patrón ya establecido en todo el dominio `Notifications` (`GenerateProgramTaskDueAlertsListener`, `HandleProgramCancelledListener` hacen exactamente lo mismo). El pattern Repository de `backend-conventions.md` aplica a los módulos de dominio CRUD (`EstablishmentHealthPlan`, etc.), no al motor de notificaciones, que ya tiene su propia capa de servicios (`AlertRecipientFactory`).
- **`AlertRecipientFactory::createForManagers()` reutilizado sin cambios**: acepta `iterable<UserProfile>` genérico, compatible con la `Collection` que retorna `listByRoleForVet()`. Sin acoplamiento a `program_manager`.
- **Catálogo WhatsApp**: `HealthPlanMonth` declara 7 placeholders distintos (`{{1}}`..`{{7}}`, contados sin duplicados) y 7 ejemplos — coincide con las 7 variables que arma `HealthPlanMonthMessageBuilder::build()` para `Channel::Whatsapp` (mismo orden: destinatario, mes, actividades, plan, categoría, cliente, establecimiento). Pineado en `WhatsappTemplateCatalogTest::test_health_plan_month_declares_seven_placeholders_with_matching_examples`.
- **`TwilioCreateTemplatesCommand::TEMPLATES`** incluye `health_plan.month`; `config/notifications.php` tiene las claves `twilio.templates.health_plan.month` y `kapso.templates.health_plan.month` con sus env vars. `KapsoCreateTemplatesCommandTest` actualizado a 6 requests (1 listado + 5 templates), coincide con los 5 `AlertType` ahora en el catálogo.
- **`DispatchDueAlerts.php`**: el único cambio en el diff es cosmético (agrega timestamp al mensaje de log de consola), sin relación funcional con esta fase. Sin impacto.

## Verificaciones cruzadas

- **`AlertMessageBuilder` (interface) vs 5 builders**: OK — firma covariante respetada en los 4 builders preexistentes, `HealthPlanMonthMessageBuilder` implementa `?MessageContent` correctamente.
- **`WhatsappTemplateCatalog` vs `HealthPlanMonthMessageBuilder` (variables 1-7)**: OK — mismo orden y cantidad, verificado con test dedicado (no vía el `builderProvider()` genérico, porque el builder necesita DB real — decisión documentada explícitamente en el test, correcta).
- **`config/notifications.php` (twilio+kapso) vs `WhatsappTemplateCatalog::definitions()`**: OK — invariante 1:1 mantenido (5 entradas en ambos lados salvo `program.created`/`program.pdf_shared`, que están fuera de `TwilioCreateTemplatesCommand::TEMPLATES` por diseño previo, no por esta fase).
- **Binding en `AppServiceProvider`**: no aplica un binding de Repository nuevo (no hay `I{Nombre}Repository` nuevo en esta fase) — sí se agregó correctamente la entrada al `morphMap()` en `boot()`.
- **`EstablishmentHealthPlanService` vs eventos**: OK — `create()`/`cancel()` disparan los eventos dentro de sus respectivas transacciones, cubierto por `EstablishmentHealthPlanServiceTest::test_create_dispatches_the_instantiated_event` / `test_cancel_dispatches_the_cancelled_event`.
- **`UserProfileRepositoryInterface` vs `UserProfileRepositoryEloquent`**: OK — `listByRoleForVet()` declarado en ambos con la misma firma.
- **Tests vs comportamiento real**: cobertura completa de los criterios de aceptación mencionados en el plan (RF-01 AC#3/AC#4, RF-02 AC#2/AC#3, RF-04 AC#2), incluyendo un feature end-to-end que ejercita `alerts:dispatch-due` real.

## Archivos revisados

- `back/app/Notifications/Data/Payloads/HealthPlanMonthPayload.php`
- `back/app/Events/EstablishmentHealthPlanInstantiatedEvent.php`
- `back/app/Events/EstablishmentHealthPlanCancelledEvent.php`
- `back/app/Listeners/GenerateHealthPlanMonthAlertsListener.php`
- `back/app/Listeners/CancelHealthPlanMonthAlertsListener.php`
- `back/app/Notifications/Builders/HealthPlanMonthMessageBuilder.php`
- `back/app/Providers/AppServiceProvider.php`
- `back/app/Notifications/Contracts/AlertMessageBuilder.php`
- `back/app/Notifications/Jobs/DeliverAlertJob.php`
- `back/app/Notifications/NotificationServiceProvider.php`
- `back/app/Notifications/Templates/WhatsappTemplateCatalog.php`
- `back/config/notifications.php`
- `back/app/Console/Commands/TwilioCreateTemplatesCommand.php`
- `back/app/Contracts/Repositories/UserProfileRepositoryInterface.php`
- `back/app/Repositories/UserProfileRepositoryEloquent.php`
- `back/app/Services/EstablishmentHealthPlanService.php`
- `back/app/Notifications/Scheduling/DispatchDueAlerts.php` (diff cosmético, sin relación)
- `back/app/Notifications/Models/Alert.php`
- `back/app/Notifications/Services/AlertRecipientFactory.php`
- `back/app/Models/EstablishmentHealthPlan.php`
- `back/app/Models/EstablishmentHealthPlanActivity.php`
- `back/app/Listeners/GenerateProgramTaskDueAlertsListener.php` (precedente, contraste)
- `back/app/Listeners/HandleProgramCancelledListener.php` (precedente, contraste)
- `back/app/Services/ProgramService.php` (precedente, contraste del patrón evento-en-transacción)
- `back/tests/Unit/Notifications/GenerateHealthPlanMonthAlertsListenerTest.php`
- `back/tests/Unit/Notifications/CancelHealthPlanMonthAlertsListenerTest.php`
- `back/tests/Unit/Notifications/HealthPlanMonthMessageBuilderTest.php`
- `back/tests/Unit/Notifications/DeliverAlertJobTest.php`
- `back/tests/Unit/Notifications/WhatsappTemplateCatalogTest.php`
- `back/tests/Feature/KapsoCreateTemplatesCommandTest.php`
- `back/tests/Feature/EstablishmentHealthPlanAlertsFeatureTest.php`
- `back/tests/Unit/EstablishmentHealthPlanServiceTest.php` (sección de eventos)
