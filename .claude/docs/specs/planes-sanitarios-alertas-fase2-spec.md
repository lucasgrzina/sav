# Spec funcional: Planes Sanitarios por establecimiento — Fase 2 (alertas automáticas mensuales)

## Contexto

La Fase 1 (`.claude/docs/specs/planes-sanitarios-establecimiento-spec.md`, ya implementada y en QA) permite instanciar un `EstablishmentHealthPlan` con su calendario materializado (`EstablishmentHealthPlanActivity`, una fila por actividad×mes) y confirmar manualmente cada actividad realizada en campo, pero deliberadamente sin ningún disparo automático de notificaciones.

Esta Fase 2 conecta ese calendario al motor de alertas real del proyecto (`Alert`/`AlertRecipient` → `alerts:dispatch-due` → `DispatchDueAlerts` → `DeliverAlertJob` → gateways), reutilizando el `AlertType::HealthPlanMonth` que ya existe reservado en el enum. El objetivo es que el vet responsable reciba, con antelación, un recordatorio agrupado de las actividades sanitarias pendientes de cada mes del plan, replicando el comportamiento que ya tenía el sistema legado para `HealthPlan`, adaptado al modelo por-actividad de la Fase 1.

## Decisiones de negocio confirmadas (no reabrir)

- **DU2-01 — Timing del disparo**: una alerta ÚNICA por `(EstablishmentHealthPlan, mes)` que agrupa TODAS las actividades de ese mes, disparada en `1er día del mes − 7 días, a las 16:00hs` — igual al legado (`reglas-negocio-alertas.md`, sección 2.4).
- **DU2-02 — Roles receptores**: únicamente usuarios con rol `vet` del tenant dueño del plan — igual al legado (`VET_VET`). NO se notifica a `vet-assistant`, `client-owner` ni `client-manager` en esta fase (a diferencia del set de confirmación de Fase 1, DU-07).
- **DU2-03 — Actividad confirmada antes del disparo**: el payload de la alerta se recalcula al momento del envío, incluyendo solo actividades del mes que sigan `pending`. Si al momento del disparo TODAS las actividades del mes ya están confirmadas, la alerta NO se envía (descarte silencioso, mismo criterio que el descarte de `ProgramTaskDue` cuando la fecha ya pasó).
- **DU2-04 — Cancelación del plan**: al cancelar un `EstablishmentHealthPlan`, se cancelan/eliminan las alertas `HealthPlanMonth` en estado `pending` de sus meses futuros — mismo efecto colateral que `ProgramCancelled` sobre `ProgramTaskDue`. Las alertas ya enviadas no se modifican.
- **DU2-05 — Recordatorios**: notificación única por `(plan, mes)`, sin recordatorios adicionales si las actividades siguen sin confirmar — igual al legado (que no tenía este mecanismo para `HealthPlanMonth`).

## Alcance

- Generación automática de alertas `HealthPlanMonth` al instanciar un `EstablishmentHealthPlan`, una por cada mes distinto con actividades materializadas.
- Cálculo de `scheduled_at` por mes (`1er día del mes − 7 días @ 16:00hs`) usando el mismo helper país-aware de Fase 1 (`HealthPlanYear`).
- Descarte silencioso de alertas cuya fecha de disparo ya haya pasado al momento de instanciar el plan.
- Recálculo del contenido (actividades pendientes) al momento del envío, no al momento de crear la alerta.
- Descarte del envío si, al momento del disparo, ya no queda ninguna actividad pendiente en ese mes.
- Destinatarios: exclusivamente usuarios con rol `vet` del tenant dueño del plan.
- Cancelación de alertas `pending` asociadas a un plan cuando el plan se cancela.

## Fuera de alcance

- Recordatorios o segundo aviso si la actividad sigue sin confirmar después del disparo inicial.
- Notificación a `vet-assistant`, `client-owner` o `client-manager` (fuera del set de destinatarios de esta fase).
- Regeneración de alertas por edición del plan — no aplica: `EstablishmentHealthPlan` no tiene flujo de edición en Fase 1 (solo instanciar/cancelar), a diferencia del `HealthPlan` legado que sí se editaba.
- Resolución del gap de portal para `client-owner`/`client-manager` (documentado en memoria de proyecto, `architecture/client-roles-no-portal-gap`) — no aplica igual en esta fase porque esos roles no son destinatarios.
- Alta/aprobación de nuevas plantillas de WhatsApp — se evalúa reutilizar/adaptar la plantilla del legado (`health_plan.month`, ver Riesgos), pero la gestión del proveedor es responsabilidad de arquitecto/dev.
- Cualquier cambio al pipeline genérico (`DispatchDueAlerts`/`DeliverAlertJob`/gateways) que no sea estrictamente necesario para soportar el recálculo dinámico del payload — el diseño concreto queda para `arquitecto`.

## Requerimientos funcionales

### RF-01 — Generación de alertas mensuales agrupadas al instanciar el plan
Como sistema, quiero generar automáticamente una alerta `HealthPlanMonth` por cada mes futuro del calendario de un `EstablishmentHealthPlan` recién instanciado, para recordarle al vet responsable las actividades sanitarias pendientes de ese mes.

Criterios de aceptación:
- Given un `EstablishmentHealthPlan` recién instanciado con actividades materializadas en N meses distintos, When se completa la instanciación, Then se crea una `Alert` de tipo `HealthPlanMonth` por cada mes distinto que tenga al menos una actividad, agrupando todas las `EstablishmentHealthPlanActivity` de ese `(plan, mes)`.
- Given el cálculo de `scheduled_at` de la alerta de un mes, When se determina la fecha de disparo, Then es `1er día del mes calendario correspondiente (vía HealthPlanYear::dateForMonth) − 7 días, a las 16:00hs`.
- Given que la fecha de disparo calculada para un mes ya pasó al momento de instanciar el plan (ej. plan instanciado a mitad del año ganadero, con meses ya transcurridos), When se evalúa ese mes, Then NO se crea la alerta de ese mes (descarte silencioso, sin log — mismo criterio que `ProgramTaskDue`).
- Given un mes sin ninguna actividad materializada, When se generan las alertas, Then no se crea alerta para ese mes.

### RF-02 — Composición dinámica del payload al momento del envío
Como sistema, quiero que el contenido de la alerta (lista de actividades) refleje el estado real de confirmación en el momento del envío, no el estado al momento de crear la alerta, para no notificar actividades que ya fueron confirmadas entre la creación de la alerta y su disparo.

Criterios de aceptación:
- Given una `Alert` de tipo `HealthPlanMonth` que llega a su `scheduled_at` y es tomada por el dispatcher de alertas, When se arma el mensaje a enviar, Then el payload (`{ month, activities }`) se recalcula consultando el estado ACTUAL de las `EstablishmentHealthPlanActivity` de ese `(plan, mes)`, incluyendo solo las que siguen `pending`.
- Given que, al momento del disparo, TODAS las actividades de ese mes ya fueron confirmadas manualmente, When se evalúa el envío, Then la alerta NO se envía (se descarta en ese momento, sin generar notificación ni error).
- Given que al momento del disparo algunas actividades siguen pendientes y otras ya fueron confirmadas, When se arma el payload, Then solo se incluyen las pendientes.

### RF-03 — Destinatarios: solo rol vet
Como veterinario responsable (tenant owner), quiero ser el único destinatario de las alertas `HealthPlanMonth`, para no generar ruido a otros roles que hoy no tienen ni portal ni responsabilidad de ejecución sobre estas actividades.

Criterios de aceptación:
- Given una `Alert` de tipo `HealthPlanMonth`, When se resuelven sus destinatarios, Then se notifica únicamente a los usuarios con rol `vet` del tenant dueño del plan (`vet_id` del `EstablishmentHealthPlan`) — regla dura #4 (scope multi-tenant).
- Given los roles `vet-assistant`, `client-owner`, `client-manager`, When se resuelven destinatarios, Then NUNCA se incluyen en esta alerta (a diferencia del set de confirmación de Fase 1, DU-07) — regla dura #2 (roles explícitos).

### RF-04 — Cancelación de alertas pendientes al cancelar el plan
Como vet, quiero que al cancelar un `EstablishmentHealthPlan`, se cancelen automáticamente las alertas `HealthPlanMonth` pendientes de sus meses futuros, para no recibir notificaciones de un plan que ya no aplica.

Criterios de aceptación:
- Given un `EstablishmentHealthPlan` con una o más `Alert` de tipo `HealthPlanMonth` en estado `pending` (no enviadas), When el vet cancela el plan, Then esas alertas se cancelan/eliminan — mismo efecto colateral que `ProgramCancelled` sobre `ProgramTaskDue`.
- Given una `Alert` `HealthPlanMonth` ya enviada antes de la cancelación, When el plan se cancela, Then esa alerta ya enviada no se modifica ni se revierte.

### RF-05 — Notificación única por mes, sin recordatorios
Como sistema, quiero enviar como máximo una alerta `HealthPlanMonth` por cada `(plan, mes)`, sin generar recordatorios adicionales si las actividades siguen sin confirmarse.

Criterios de aceptación:
- Given un `(plan, mes)` ya notificado (alerta enviada), When pasa el tiempo y las actividades de ese mes siguen `pending`, Then NO se genera ninguna alerta adicional para ese mismo `(plan, mes)`.
- Given el `due_date` de las actividades de un mes (1er día de ese mes), When se cumple esa fecha sin confirmación, Then no hay ningún disparo adicional asociado a esa fecha en esta fase.

## Requerimientos no funcionales

- **Performance**: la generación de las N alertas mensuales al instanciar el plan debe hacerse en batch, sin N+1 inserts, idealmente en la misma transacción (o inmediatamente después vía evento síncrono) del `EstablishmentHealthPlanService::create()` de Fase 1.
- **Seguridad / multi-tenant**: la resolución de destinatarios (`vet`) debe estar estrictamente acotada al `vet_id` dueño del plan — regla dura #4, no negociable.
- **Auditoría**: la creación y cancelación de cada `Alert`/`AlertRecipient` queda registrada con los mecanismos ya existentes del motor de alertas (sin campos nuevos de auditoría requeridos por esta feature).
- **Multi-país**: `scheduled_at` debe calcularse reutilizando `App\Support\HealthPlanYear` (ya país-aware desde Fase 1, DEC-08), nunca hardcodeando julio-junio. El timezone fijo `America/Argentina/Buenos_Aires` usado por el legado para todos los cálculos queda como riesgo a resolver por arquitecto (ver Riesgos) antes de habilitar clientes fuera de Argentina.

## Diseño a alto nivel (insumo para arquitecto — no vinculante)

Estas notas describen la forma conceptual del flujo, no una decisión técnica cerrada:

- **Evento de dominio**: análogo a `ProgramTargetsChangedEvent`, algo como `EstablishmentHealthPlanInstantiatedEvent`, disparado al finalizar `EstablishmentHealthPlanService::create()`.
- **Listener de generación**: análogo a `GenerateProgramTaskDueAlertsListener`, pero agrupando por mes en vez de iterar tareas individuales: recorre los meses distintos del calendario recién materializado, calcula `scheduled_at` por mes, aplica el descarte de RF-01, y crea una `Alert` + `AlertRecipient` por mes válido.
- **Evento y listener de cancelación**: análogo a lo que dispara `ProgramCancelled`, sobre un evento de cancelación del plan (o directamente dentro de `EstablishmentHealthPlanService::cancel()`), que cancela las `Alert` `pending` cuyo subject sea ese plan.
- **`MessageBuilder` nuevo** (`HealthPlanMonthMessageBuilder` o equivalente): a diferencia de los builders existentes (que arman el payload con datos ya fijados al crear la `Alert`), este necesita **recalcular en el momento del envío** el set de actividades pendientes de ese `(plan, mes)`, y ser capaz de señalizar "no enviar" si no queda ninguna pendiente (RF-02). Esta capacidad de recálculo-en-destino y de aborto condicional no tiene precedente exacto en el pipeline actual — es la pieza de diseño más importante para el arquitecto.
- **Subject polimórfico de `Alert`**: dado que una sola alerta agrupa varias `EstablishmentHealthPlanActivity` de un mismo mes, el subject natural es el `EstablishmentHealthPlan` (no una actividad puntual), con el `mes` representado de alguna forma adicional (columna propia vs. payload) — a definir por arquitecto.
- **Plantilla de WhatsApp**: el legado ya tenía una plantilla aprobada para `health_plan.month` (`reglas-negocio-alertas.md`, sección 4.4) con variables `{destinatario, mes, actividades, nombre del plan, categoría, cliente, establecimiento}`. La "categoría" sigue siendo alcanzable en el modelo nuevo vía `$plan->template->category`. Si se reutiliza el mismo proveedor/cuenta de Twilio, evaluar reutilizar el `contentSid`; si no, hay que re-aprobar.

## Impacto en dominio SAV

- **Protocolos / tareas**: sin impacto directo. Esta feature no toca `Protocol`/`ProtocolTask`/`Program`; reutiliza el mismo motor de alertas (`Alert`/`AlertRecipient`/`DispatchDueAlerts`/`DeliverAlertJob`/gateways) que ya usa `protocols`, sin modificar su pipeline para los tipos de alerta existentes.
- **Alertas y notificaciones**: implementa `AlertType::HealthPlanMonth` (ya reservado en el enum). Introduce, por primera vez en el proyecto, una alerta cuyo payload se recalcula dinámicamente al momento del envío en vez de fijarse al crearla — comportamiento nuevo respecto al resto de tipos de alerta existentes.
- **Planes sanitarios**: conecta el calendario materializado de Fase 1 (`EstablishmentHealthPlan` / `EstablishmentHealthPlanActivity`) al motor de notificaciones real. No cambia el modelo de datos de Fase 1 (snapshot, confirmación manual, año ganadero por país).
- **Animales / Establecimientos**: sin impacto adicional al ya descrito en Fase 1 (el plan sigue siendo por establecimiento, no por animal).
- **Roles y permisos**: no se agregan permisos nuevos — esta feature no expone ningún endpoint nuevo, es puramente generación/envío de notificaciones en background. El set de destinatarios (`vet`) es una decisión de negocio (DU2-02), no un permiso Spatie.
- **Multi-tenant**: los destinatarios se resuelven exclusivamente entre usuarios `vet` del mismo `vet_id` dueño del plan — regla dura #4.
- **Multi-país**: `scheduled_at` se calcula con el mismo helper país-aware de Fase 1 (`HealthPlanYear`). El timezone fijo del legado (`America/Argentina/Buenos_Aires`) es un riesgo pendiente de resolver (ver Riesgos).

## Riesgos y alertas

- **RIESGO CRÍTICO — payload dinámico en destino sin precedente en el pipeline actual.** RF-02 requiere que el contenido de la alerta se recalcule al momento del envío (no al crearla) y que el envío se pueda abortar si ya no aplica. Ninguno de los `MessageBuilder` existentes (`ProgramTaskDueMessageBuilder`, etc.) tiene esta semántica — es la pieza de diseño más importante que debe resolver `arquitecto` antes de implementar, y puede requerir un hook nuevo en `DeliverAlertJob` o en el pipeline de `DispatchDueAlerts`.
- **RIESGO — subject polimórfico de `Alert` para una alerta que agrupa múltiples actividades.** `Alert.subject()` es `MorphTo` (apunta a UN modelo). Agrupar por mes implica definir cómo se representa "de qué mes" es cada alerta cuando el subject es el plan completo (que puede tener varias alertas, una por mes). No bloquea esta spec funcional, pero condiciona el modelo de datos que diseñe arquitecto.
- **RIESGO — timezone fijo del legado.** `reglas-negocio-alertas.md` fija `America/Argentina/Buenos_Aires` para todos los cálculos de alertas del sistema viejo. Si esta feature hereda ese hardcodeo sin parametrizar, viola la regla dura #5 (multi-país desde el diseño) apenas exista un cliente fuera de Argentina con plan sanitario. Debe resolverse por país igual que ya se resolvió el mes de inicio del año ganadero (`HealthPlanYear`, DEC-08 de Fase 1).
- **RIESGO — plantilla de WhatsApp legada atada a una cuenta Twilio específica.** El `contentSid` documentado (`HXb734a385bc5cdc6bbe1dfac9dee85fb7`) pertenece a la cuenta del sistema legado; si el proyecto nuevo usa otro proveedor/cuenta, hay que re-aprobar la plantilla antes de poder enviar por WhatsApp — no bloqueante para email/push, sí para ese canal específico.
- **RIESGO — descarte silencioso duplicado en dos momentos distintos.** Existen dos descartes con semántica distinta que no deben confundirse en la implementación: (1) descarte en creación (RF-01, fecha de disparo ya pasada), y (2) descarte en envío (RF-02, todas las actividades ya confirmadas). Son condiciones distintas, evaluadas en momentos distintos del ciclo de vida de la alerta.

## Dudas abiertas para el humano

Ninguna — las 5 decisiones de negocio bloqueantes (timing, roles, confirmación previa, cancelación del plan, recordatorios) fueron confirmadas explícitamente y quedan documentadas en "Decisiones de negocio confirmadas". Las preguntas remanentes (representación del `month` en el subject polimórfico, mecanismo de recálculo dinámico en `DeliverAlertJob`, timezone parametrizado por país, reutilización o re-aprobación de la plantilla de WhatsApp) son decisiones técnicas y quedan explícitamente delegadas a `arquitecto` en la sección "Diseño a alto nivel" y "Riesgos y alertas".
