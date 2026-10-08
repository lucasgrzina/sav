# Spec funcional: Planes Sanitarios por establecimiento (Fase 1 — instanciar + calendario)

## Contexto

Hoy conviven dos sistemas paralelos bajo "sanidad": el catálogo `health` (admin, huérfano — `HealthPlanTemplate`/`HealthActivity`/`HealthPlanCategory`, sin consumidor) y el motor real de alertas `protocols` (`Protocol`/`Program`/`Alert`). El sistema legado (`sav-back-develop`, descontinuado) sí tenía un `HealthPlan` funcional: un plan sanitario anual por establecimiento, instanciado por el vet a partir de categorías/actividades, con calendario mensual y alertas propias.

Esta spec cierra la primera mitad de esa brecha: permite que un vet tenant tome un `HealthPlanTemplate` del catálogo admin y lo instancie para un establecimiento concreto de un cliente suyo, generando el calendario real de actividades sanitarias por mes dentro del año ganadero. La generación de alertas automáticas queda fuera de esta iteración (ver "Fuera de alcance" y "Trabajo futuro").

## Decisiones de negocio confirmadas (no reabrir)

- **DU-01 — Modelo de datos**: entidad propia `EstablishmentHealthPlan`, independiente de `Protocol`/`Program`. Referencia `establishment_id` + `health_plan_template_id` + año (ganadero). El `AlertType::HealthPlanMonth` que ya existe en `App\Notifications\Enums\AlertType` se implementará en Fase 2 con su propio listener/builder sobre este subject, sin tocar el pipeline de `Program`.
- **DU-02 — Quién instancia**: solo el vet tenant (rol `vet`, alcance a confirmar si incluye `vet-administrative` — ver DU-08 abajo). Sin contraparte en el panel SuperAdmin, igual que el legacy.
- **DU-03 — UI admin**: ninguna pantalla/funcionalidad nueva en el panel SuperAdmin. El catálogo (`HealthActivity`, `HealthPlanCategory`, `HealthPlanTemplate`) ya está completo (DEC-10, `health-admin-module.md`). Lo único pendiente es exponer un endpoint de **lectura** del catálogo accesible desde el panel tenant.
- **DU-04 — Alcance por fases**: esta spec cubre **Fase 1** (instanciar plan + calendario visible + confirmación manual de actividades, sin alertas automáticas). La Fase 2 (conectar `HealthPlanMonth` al motor de alertas) queda documentada como trabajo futuro, no se implementa ahora.

## Alcance (Fase 1)

- Endpoint de lectura del catálogo (`HealthPlanTemplate` con su `category` y `activities`+`months`) accesible desde el panel tenant, de solo lectura.
- Instanciar un `EstablishmentHealthPlan` para un establecimiento de un cliente del vet autenticado, a partir de un `HealthPlanTemplate` y un año ganadero.
- Materialización automática del calendario: una entrada por cada combinación actividad×mes definida en el template, acotada al año ganadero del plan.
- Vista de calendario/detalle del plan (matriz mes × actividad) con estado de cada actividad (pendiente / confirmada).
- Confirmación manual de que una actividad del calendario fue realizada en campo (`require_confirmation`, `confirmed_at`, `confirmed_by`).
- Listado de planes instanciados por vet, filtrable por cliente/establecimiento.
- Cancelación de un plan instanciado.
- Scope multi-tenant estricto: el vet solo puede instanciar/ver/gestionar planes de establecimientos de sus propios clientes.

## Fuera de alcance

- Generación y envío de alertas automáticas (`AlertType::HealthPlanMonth`, listener, `HealthPlanMonthMessageBuilder`) — Fase 2, documentada como trabajo futuro.
- Cualquier cambio en el panel SuperAdmin / catálogo (`HealthActivity`, `HealthPlanCategory`, `HealthPlanTemplate` CRUD ya existente no se modifica).
- Instanciación por parte de SuperAdmin en nombre de un cliente.
- Edición del `HealthPlanTemplate` de origen desde el flujo de instanciación (el vet consume el catálogo, no lo modifica).
- Notificación por WhatsApp/email de la confirmación — Fase 1 es solo registro (`confirmed_at`/`confirmed_by`), sin disparo de notificación.
- Vinculación de actividades a animales individuales (el plan es por establecimiento, no por animal, igual que el legado).

## Requerimientos funcionales

### RF-01 — Catálogo de templates sanitarios de solo lectura para el panel tenant
Como vet, quiero consultar los `HealthPlanTemplate` disponibles (con su categoría y actividades asignadas por mes) para elegir cuál instanciar en un establecimiento de mi cliente.

Criterios de aceptación:
- Given un vet autenticado, When solicita el catálogo, Then recibe la lista de templates con `category` y `activities` (nombre + meses), en modo solo lectura.
- Given un vet autenticado, When intenta crear, editar o eliminar un template desde este endpoint, Then la operación no existe (el CRUD del catálogo sigue siendo exclusivo de `/v1/admin/*`).

### RF-02 — Instanciar un Plan Sanitario para un establecimiento
Como vet, quiero instanciar un `HealthPlanTemplate` para un establecimiento de un cliente mío, indicando el año ganadero, para generar el calendario real de actividades sanitarias de ese establecimiento.

Criterios de aceptación:
- Given un establecimiento que pertenece a un cliente del vet autenticado y un template válido del catálogo, When el vet instancia el plan, Then se crea un `EstablishmentHealthPlan` (`establishment_id`, `health_plan_template_id`, `year`, `vet_id`) y se materializa el calendario: una fila por cada actividad×mes del template, dentro del rango del año ganadero.
- Given un establecimiento que NO pertenece a un cliente del vet autenticado, When intenta instanciar, Then la operación se rechaza (403) — scope multi-tenant obligatorio (regla dura #4).
- Given el año del plan, When se calcula el rango de fechas del calendario, Then el inicio de año se resuelve según el país del cliente del establecimiento (`Client.country_id`), NO hardcodeado a julio-junio. Todos los países, incluida Argentina, usan enero→diciembre (actualizado 2026-09-30; antes AR era julio→junio), pero el mes de inicio sigue parametrizado por país (`HealthPlanYear`). Ver DU-06 sobre parametrización del catálogo por país.

### RF-03 — Calendario de actividades del plan instanciado
Como vet o vet-assistant, quiero ver el calendario (matriz mes × actividad) de un `EstablishmentHealthPlan`, para saber qué actividad corresponde en cada mes y su estado.

Criterios de aceptación:
- Given un `EstablishmentHealthPlan` existente y accesible por el vet autenticado, When se consulta su detalle, Then se devuelve la lista de actividades materializadas con su mes, su actividad de origen (`HealthActivity`) y su estado (`pending` / `confirmed`).
- Given un plan de un establecimiento que no pertenece al vet autenticado, When se intenta consultar, Then se rechaza (403/404 según convención del proyecto).

### RF-04 — Confirmación manual de actividad realizada
Como usuario autorizado de campo, quiero confirmar que una actividad sanitaria programada fue realizada, para dejar registro de cumplimiento del plan.

Criterios de aceptación:
- Toda actividad materializada del calendario tiene `require_confirmation = true` (regla dura #7 — toda tarea que se ejecuta físicamente en campo requiere confirmación).
- Given una actividad en estado `pending`, When el usuario autorizado la confirma, Then se setea `confirmed_at` (timestamp) y `confirmed_by` (FK a `UserProfile`), y su estado pasa a `confirmed`.
- Given una actividad ya `confirmed`, When se intenta confirmar de nuevo, Then no se duplica el registro (idempotente o rechazo explícito — a definir por arquitecto).
- Los roles habilitados para confirmar quedan como duda abierta explícita (DU-07) — regla dura #2 exige roles explícitos, no "el usuario" genérico.

### RF-05 — Listado de Planes Sanitarios instanciados
Como vet, quiero listar los `EstablishmentHealthPlan` de mis clientes/establecimientos, filtrando por cliente o establecimiento, para gestionarlos.

Criterios de aceptación:
- Given un vet autenticado, When lista sus planes, Then recibe solo los planes de establecimientos de sus propios clientes (scope multi-tenant), paginados.
- Given un filtro por `establishment_guid` o `client_guid`, When se aplica, Then la lista se acota correctamente.

### RF-06 — Cancelación de un Plan Sanitario instanciado
Como vet, quiero cancelar un `EstablishmentHealthPlan` que ya no corresponde, sin perder el historial de actividades ya confirmadas.

Criterios de aceptación:
- Given un plan con actividades ya confirmadas, When el vet lo cancela, Then el plan pasa a un estado cancelado (ej. `cancelled_at`, análogo al patrón ya usado en `Program.cancelled_at`) y NO se hace hard delete del historial de confirmaciones.
- Given un plan sin ninguna actividad confirmada, When el vet lo elimina, Then el comportamiento (soft-cancel vs hard delete) sigue el mismo patrón que el resto del sistema — a confirmar por arquitecto, no hay precedente de hard delete de `Program` en el codebase actual.

## Requerimientos no funcionales

- **Performance**: la materialización del calendario al instanciar (actividad × mes del template) debe hacerse en una única transacción batch, no N+1 inserts por fila.
- **Seguridad / multi-tenant**: toda query de `EstablishmentHealthPlan` y de sus actividades materializadas DEBE filtrar por el `vet_id` del usuario autenticado (via `establishment.client.vet_id` o campo `vet_id` propio del plan) — regla dura #4, no negociable.
- **Auditoría**: quién instanció el plan y cuándo (`created_at`/`created_by` si el patrón del proyecto lo usa), y quién confirmó cada actividad y cuándo (`confirmed_by`/`confirmed_at`) — regla dura #7.
- **Multi-país**: el cálculo del año ganadero del plan debe resolverse por país del cliente/establecimiento (`regulations-by-country.md` — todos enero desde 2026-09-30; antes AR: julio), no hardcodeado. El catálogo (`HealthPlanTemplate`/`HealthActivity`) hoy es global sin variante por país — ver riesgo DU-06.

## Impacto en dominio SAV

- **Protocolos / tareas**: sin impacto. `EstablishmentHealthPlan` es independiente de `Protocol`/`ProtocolTask`/`Program` (DU-01). No se reutiliza `days_offset`/`time_of_day` — el eje temporal de sanidad es mes del año ganadero, no offset de días desde D0.
- **Alertas y notificaciones**: sin generación de alertas en esta fase. `AlertType::HealthPlanMonth` ya existe en el enum (`App\Notifications\Enums\AlertType`) y `Alert.subject()` es polimórfico (`MorphTo`), listo para colgar un subject `EstablishmentHealthPlan` en Fase 2 sin cambios al pipeline `DispatchDueAlerts`/`DeliverAlertJob`/gateways existente.
- **Planes sanitarios**: entidad nueva `EstablishmentHealthPlan` (+ detalle de actividades materializadas por mes), consumidora de `HealthPlanTemplate` (catálogo admin existente, sin cambios). Año del plan = enero→diciembre en todos los países (2026-09-30; antes AR julio→junio), parametrizable por país.
- **Animales / Establecimientos**: el plan se liga a `Establishment`, no a `Animal` individual — consistente con el legado.
- **Roles y permisos**: instanciar y ver el plan = rol `vet` tenant (alcance de `vet-administrative` a confirmar, DU-08). Confirmar actividad realizada = roles a confirmar explícitamente (DU-07). Sin rol admin involucrado (DU-02, DU-03).
- **Multi-tenant**: `EstablishmentHealthPlan` debe resolver y persistir el `vet_id` del tenant dueño al crearse; todo acceso posterior debe scopear por ese `vet_id`.
- **Multi-país**: cálculo de año ganadero parametrizado por país del cliente. El catálogo global sin variante por país es un riesgo separado (ver Riesgos y alertas).

## Riesgos y alertas

- **ALERTA — Catálogo `HealthPlanTemplate`/`HealthActivity` sin `country_id`, a diferencia de `Protocol` (que sí tiene `country_id`).** Actividades sanitarias obligatorias varían por país (ej. vacuna Aftosa: AR `[4,10]`, MX `[3,9]` según `regulations-by-country.md`). Si el catálogo sigue siendo 100% global, un template instanciado en un establecimiento de otro país aplicará meses pensados para Argentina. No bloquea Fase 1 (mercado inicial es AR), pero es un riesgo de arquitectura a resolver antes de habilitar clientes fuera de AR — coherente con la regla dura #5 (multi-país desde el diseño).
- **RIESGO — Año ganadero hardcodeado.** Si la implementación hardcodea el mes de inicio sin leer el país del cliente, viola la regla dura #3 apenas exista un cliente fuera de Argentina. El cálculo del rango de fechas del calendario debe parametrizarse desde el día 1, aunque hoy todos los tenants sean AR.
- **RIESGO — `require_confirmation` sin roles explícitos definidos (DU-07).** La regla dura #2 exige roles explícitos para cualquier interacción de confirmación/alerta. Este documento deja el set de roles habilitados a confirmar como pregunta abierta; no debe asumirse "cualquier usuario autenticado del tenant".
- **RIESGO — Duplicación de instancias.** No está definido si el sistema permite instanciar el mismo template dos veces para el mismo establecimiento/año, o si debe bloquearse (análogo a DEC-08 del catálogo admin, que bloquea borrado de categorías/actividades en uso). Ver DU-05.
- **RIESGO — Snapshot vs referencia viva al template.** Si `EstablishmentHealthPlan` solo referencia `health_plan_template_id` y el admin edita el template después (agrega/quita actividades o meses), no está definido si los planes ya instanciados deben congelar su calendario original o reflejar el cambio. Afecta integridad histórica del cumplimiento. Ver DU-06.

## Dudas abiertas para el humano

- **DU-05** — ¿Se permite instanciar el mismo `HealthPlanTemplate` más de una vez para el mismo establecimiento y año ganadero, o debe bloquearse (1 plan activo por template+establecimiento+año)?
- **DU-06** — Al instanciar, ¿el calendario se genera como snapshot congelado de las actividades/meses del template en ese momento, o queda "vivo" y refleja ediciones futuras del template admin? (Impacta integridad histórica y necesidad futura de `country_id` en el catálogo.)
- **DU-07** — ¿Qué roles (`vet`, `vet-assistant`, `client-owner`, `client-manager`) pueden confirmar que una actividad del calendario fue realizada? Regla dura #2 exige explicitarlo, no puede quedar implícito.
- **DU-08** — ¿El rol `vet-administrative` (recepción/admin del consultorio) puede instanciar planes en nombre del vet, o esa acción queda reservada exclusivamente al rol `vet`?
- **DU-09** — ¿Qué pasa con los planes instanciados y sus confirmaciones si el establecimiento cambia de vet (reasignación de cliente)? No hay precedente confirmado en el sistema actual para este escenario de reasignación multi-tenant.
