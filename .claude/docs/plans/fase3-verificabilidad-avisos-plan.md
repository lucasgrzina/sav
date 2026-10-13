# Plan técnico: Fase 3 — Verificabilidad de la automatización y WhatsApp con contexto

## Input procesado

- Spec funcional: `.claude/docs/specs/fase3-verificabilidad-avisos.md` (leída completa).
- Minuta origen: `docs/Analisis y correcciones V1.md` (secciones 4 y 5).
- Decisiones del usuario ya cerradas (no se reabren): DU-01 (no bloquea en servidor, confirmación UI solo ante `critical`, la respuesta de guardado trae advertencias autoritativas), DU-02 (soft-cancel con motivo), DU-04 (sin aviso automático al reprogramar), DU-06 (plantilla v2 con pie de opt-out; ampliar comandos Kapso y Twilio), DU-08 (zona horaria del vet), "Requiere confirmación" solo informativo (nada de respuestas/confirmaciones; entregado/leído solo con dato real). H1 lo corrige OTRO agente (hotfix mínimo en el listener); H2 entra en este plan.
- Convenciones: `sav-domain-rules.md`, `backend-conventions.md`, `frontend-conventions.md`. No existen `CLAUDE.md` en raíz, `back/` ni `front/` (solo `.claude/CLAUDE.md` y `AGENTS.md`).
- Nombre del archivo: se usa el sufijo `-plan` (convención mayoritaria en `.claude/docs/plans/`, ej. `planes-sanitarios-alertas-fase2-plan.md`) para no chocar con la spec homónima.

## Resumen ejecutivo

La fase hace verificable el circuito protocolo -> tarea -> aviso -> envío. Se construye sobre cuatro piezas backend: (1) un único servicio de proyección que usan el detalle, el preview, las advertencias y la generación real; (2) un reconciliador por clave lógica que reemplaza el "borrar todo y recrear" del listener, con soft-cancel y exclusión mutua frente al dispatcher; (3) trazabilidad de envíos (`failure_code`, `destination_hint`, `rendered_body`, `template_version`) y revalidación al enviar; (4) plantilla WhatsApp v2 versionada en el catálogo, detrás de una bandera. El frontend suma la pestaña "Avisos" (solo lectura + reenviar/recalcular), el panel "Revisá los avisos" previo a guardar con diff, y la confirmación de advertencias críticas. Se entrega en 15 PRs propios (más el hotfix PR-0 del otro agente), con expand/contract para poder aplicar el SQL en producción (phpMyAdmin, sin SSH) antes de desplegar el código.

## Estado de partida verificado en el código (12/10/2026)

- `GenerateProgramTaskDueAlertsListener` (`back/app/Listeners/`) estaba SIN parchear al leerlo: borra todas las `pending` y descarta `isPast()`. El otro agente dejó (sin commitear) `back/tests/Feature/GenerateProgramTaskDueAlertsListenerTest.php` con 5 tests H1. **Releer el listener y ese test al empezar PR-5**; este plan parte del listener ya parchado y lo reemplaza. Esos 5 tests son la suite de regresión del reconciliador (ver PR-5 para los 2 asserts que cambian por soft-cancel).
- Los eventos/listeners se autodescubren (no hay `Event::listen` en providers): al cambiar el cuerpo del listener no hay que tocar registros.
- `ProgramService::create/update` disparan `ProgramTargetsChangedEvent` dentro de su `DB::transaction`; `ProgramManagerLinkService::regenerateAlerts` también (dentro de otra transacción). `HandleProgramCancelledListener` borra duro las `task_due` `pending`.
- `AlertRecipient` tiene `unique(alert_id, user_profile_id, channel)`; `alert_recipients.user_profile_id` es `cascadeOnDelete` y `UserProfileRepositoryEloquent::delete` borra perfiles realmente (H8 es real).
- `ProtocolService::countProgramsForProtocol()` es un STUB que devuelve 0 (discrepancia previa: el bloqueo de `technique_id` nunca se activa). No se toca (ver Riesgos).
- `ProtocolTask`/`ProtocolTaskAlert` usan `SoftDeletes`: los guids de tareas/alertas borradas siguen resolviéndose (útil para revalidar y para el diff).
- `TemplateNotConfiguredException extends NotificationConfigurationException` (un solo `catch` cubre ambas).
- `phpunit.xml`: SQLite en memoria, `QUEUE_CONNECTION=sync`; prod usa `QUEUE_CONNECTION=database` (de `.env.example`). Los tests existentes son clases PHPUnit con `RefreshDatabase`, guid explícito y `Carbon::setTestNow`. `lockForUpdate()` es no-op en SQLite: la exclusión mutua se diseña con compare-and-set (CAS) de una sentencia, testeable en SQLite.
- `routes/console.php` solo agenda `alerts:dispatch-due` (cada minuto). La CLI por defecto del equipo es PHP 7.4: correr tests con el binario Laragon 8.4 (`C:\laragon\bin\php\php-8.4.16-Win32-vs17-x64\php.exe`).
- El módulo `programs` del front hoy tiene strings en español hardcodeados (no usa `$t`; los locales `es` solo tienen `auth`, `global`, `support-messages`).
- Working tree sucio por trabajo de otros (Fase 1/2): `VetProgramFormPage.vue`, `ProgramsTable.vue`, `ProtocolFormDrawer.vue`, `VetProtocolFormDrawer.vue`, etc. Los PRs FE 14 y 16 tocan esos archivos: rebasar sobre lo que esté mergeado.

## Decisiones tomadas

DEC-01 — Servicio único de proyección, puro y sin escritura
  Decisión: `App\Services\ProgramAlerts\ProgramAlertProjector::project(ProjectionInput): ProgramProjection`. Recibe un "programa en borrador" (vet, protocolo con tareas/alertas, grupos `{ref, guid?, date}`, responsables, `now`), no un `Program` persistido, porque el preview de alta no tiene programa. Lo consumen: `ProgramService` (detalle), el reconciliador (generación), el servicio de advertencias y el preview.
  Justificación: RT-01; hoy el listener y `projectTargetTasks` divergen (fechas pasadas, sin destinatarios, hora local sin zona).
  Alternativa descartada: que el preview llame al listener en modo "dry-run": mezcla escritura y lectura y no sirve para alta.

DEC-02 — Clave lógica a nivel de alerta, persona a nivel de destinatario
  Decisión: `alerts.logical_key = sha1("{programGuid}|{type}|{targetGuid}|{protocolTaskAlertGuid}|{scheduled_at UTC 'Y-m-d H:i:s'}")`, `CHAR(40)` nullable con índice único. La persona queda en `alert_recipients` (ya único por `alert_id, user_profile_id, channel`). Al cancelar un aviso se pone `logical_key = NULL` (el MySQL y SQLite permiten varios NULL en un unique) para poder recrear la misma clave si el usuario vuelve a la fecha anterior; un aviso ya `dispatched` conserva su clave, así "reprogramar de vuelta a una fecha ya enviada" nunca reenvía (RF-16).
  Justificación: unique simple, sin índices parciales (MySQL no los soporta). Equivale a la clave de la spec (programa + grupo + alerta + fecha + persona) repartida en dos niveles.
  Alternativa descartada: guardar `program_target_id` con FK: los grupos se borran duro en `syncTargets` y el cascade destruiría el historial soft-cancel.

DEC-03 — Exclusión mutua por CAS, no por locks
  Decisión: toda transición de `alerts.status` es un `UPDATE ... WHERE id = ? AND status = 'pending'` y se verifica `affected rows === 1`. (a) `DispatchDueAlerts` reclama primero (`pending -> dispatched`) dentro de una transacción y recién después carga los destinatarios `pending` y despacha los jobs con `->afterCommit()`. (b) El reconciliador modifica/cancela alertas solo con el mismo CAS; si devuelve 0, la alerta se contabiliza como `untouched_dispatched`. (c) Para serializar reconciliaciones concurrentes del mismo programa: `Program::whereKey($id)->lockForUpdate()` al inicio (efectivo en MySQL, no-op en SQLite).
  Justificación: es la única técnica verificable con SQLite en tests y correcta en MySQL/InnoDB. Cumple I2.
  Alternativa descartada: `lockForUpdate` sobre alertas: no testeable y propenso a deadlocks con el dispatcher en chunks.
  Riesgo residual aceptado: si el proceso muere entre el commit del claim y el dispatch quedan destinatarios `pending` en una alerta `dispatched`; se mitiga con `afterCommit` (ventana de milisegundos) y queda anotado como pendiente un barrido de "atascados".

DEC-04 — Soft-cancel de alertas y supresión (no borrado) de destinatarios quitados
  Decisión: nuevo estado `alerts.status = 'cancelled'` + `cancelled_at` + `cancel_reason` (enum `AlertCancelReason`: `rescheduled|protocol_changed|recipient_removed|group_removed|program_cancelled|superseded`). Un responsable quitado NO se borra: su `AlertRecipient` `pending` pasa a `suppressed` con `failure_reason='no_longer_applicable'`/`failure_code=no_longer_applicable`; si se lo vuelve a agregar antes del envío, la fila se reactiva (`pending`, sin código). Si todos los destinatarios de una alerta quedan suprimidos, la alerta se cancela con `recipient_removed`.
  Justificación: DU-02; trazabilidad ("¿por qué Juan no recibió?").
  Alternativa descartada: `DELETE` de destinatarios pendientes (lo que dice literalmente RF-11): pierde la explicación.

DEC-05 — La reconciliación se invoca directo desde los servicios; el evento queda como entrada secundaria
  Decisión: `ProgramService::create/update` llaman `ProgramAlertReconciler::reconcile()` directamente (necesitan el reporte y las advertencias para la respuesta) y dejan de disparar `ProgramTargetsChangedEvent`. El evento y el listener se conservan, con el listener reducido a delegar en el reconciliador (lo usan `ProgramManagerLinkService` y los tests del hotfix). Una sola implementación.
  Justificación: un listener no puede devolver valores; evita doble reconciliación.
  Alternativa descartada: singleton de "último reporte" leído tras `event()`: acoplamiento oculto.

DEC-06 — Payload v2 con snapshot mínimo + datos "vivos" para nombres
  Decisión: `ProgramTaskPayload` suma campos nullable: `programTargetGuid`, `protocolTaskGuid`, `targetDate` (Y-m-d), `taskDescription`, `taskDate` (Y-m-d), `taskTime` (HH:mm), `multiGroup` (bool), `contextVersion` (int, 2). Nombres de vet, cliente, establecimiento y protocolo se leen vivos al enviar (el builder ya carga el programa). El reconciliador refresca el payload de las alertas `pending` cuando cambió (propaga H4 en edición de texto/descripción).
  Justificación: la descripción de tarea y la fecha deben coincidir con lo que se previsualizó; los nombres casi no cambian y duplicarlos aumenta el desvío.
  Compatibilidad: payload sin `contextVersion` = alerta legacy: se envía siempre con v1 (DEC-12).

DEC-07 — Estados derivados y paginación calculados en PHP sobre una consulta liviana
  Decisión: `ProgramAlertLineQuery` trae (alertas del programa x destinatarios no-push) con columnas mínimas, agrupa en memoria por `(alert, profile)`, deriva el estado con `AlertLineStateResolver` (una sola implementación) y pagina en servidor. Contadores respetan los filtros persona/grupo/tipo pero NO el filtro de estado (si no, las pestañas internas mostrarían 0). Cota: se loguea `warning` si un programa supera 5.000 líneas.
  Justificación: ~1.300 filas en el peor caso razonable; evita duplicar la lógica de estados en SQL (CASE/HAVING) y diferencias SQLite/MySQL.
  Alternativa descartada: columna materializada de estado: sobre-ingeniería hasta medir.
  Índices: no se agrega índice compuesto con `subject_type` (VARCHAR(255) utf8mb4 = 1020 bytes; `vet_id + subject_type + type + status` supera el límite de 3072 bytes de InnoDB). Alcanzan el morph index existente `(subject_type, subject_id)` y el FK de `alert_recipients.alert_id`.

DEC-08 — Fechas al frontend: objeto `LocalDateTime` listo para mostrar
  Decisión: toda fecha-hora de aviso se serializa como `{ iso, date, time, weekday, weekday_short, label }` en la zona del vet (ej. `label: "jue 24/09/2026 09:00"`, `iso` con offset). También se envía `scheduled_at_utc`. El frontend muestra `label`/`date`/`time` sin convertir zonas (RT-02) ni calcular fechas (regla 8). El nombre del día se calcula con `->locale($locale)->isoFormat()` con locale explícito (`config('notifications.locale_by_country_iso')`, default `es`), nunca el del servidor.

DEC-09 — Advertencias: tres modos con la misma lógica
  Decisión: `ProgramAlertWarningService::build(ProgramProjection, WarningMode $mode, ExistingAlertIndex $existing)` con `WarningMode::{Preview, Save, Tab}`.
  - Preview y Save: todas las reglas. `ALERT_IN_PAST` = ítem pasado con destinatarios y SIN alerta existente (ningún estado) para esa clave; en Save equivale exactamente a `skipped_past` del reporte.
  - Tab (`meta.warnings` del listado): evita ruido histórico de programas viejos: `ALERT_IN_PAST` baja a severidad `info` y las advertencias de destinatario/rol solo cuentan ítems futuros. El banner "N avisos no se van a enviar" suma solo `critical` en modo Tab.
  Reglas de combinación: sin destinatarios por rol -> solo `ALERT_ROLE_WITHOUT_RECIPIENT` (no además IN_PAST); sin WhatsApp y SIN correo habilitado -> `RECIPIENT_WITHOUT_WHATSAPP` (critical, `email_fallback:false`); sin WhatsApp y CON correo -> solo `RECIPIENT_EMAIL_ONLY` (warning) (cumple el criterio de RF-07 sobre correo de respaldo). Más de 5 del mismo código en un mismo grupo -> una sola con `count` y `context.items[]` (máx. 50) — extensión del contrato de la spec, a avisar.
  Desvío respecto de la spec: severidad `info` de `ALERT_IN_PAST` solo en modo Tab (la spec lo marca critical sin distinguir contexto).

DEC-10 — Validación de teléfono liviana y consistente entre preview y envío
  Decisión: no se agrega `libphonenumber`. `App\Notifications\Support\PhoneValidator::isPlausible(string $digits): bool`: solo dígitos, 8–15 (E.164) y, si el prefijo coincide con un país conocido (`countries.phone_prefix`, match por prefijo más largo), el rango de longitudes de `config/phone_rules.php` (AR 12–13 conservando el 9 móvil, MX 12–13, CL 11, CO 12, PE 11, BR 12–13; rangos laxos). `PhoneMasker::mask(digits)` -> `+54 ••• •••• 4821` (prefijo de país + últimos 4); emails -> `m•••@dominio.com`. El MISMO validador se usa en preview/advertencias y en el pre-chequeo de `DeliverAlertJob` (`invalid_number` sin llamar al proveedor), para que lo previsualizado sea lo que ocurre.
  Justificación: sin dependencias nuevas; sin lógica exclusiva de Argentina (regla 5). Los rangos son laxos para no bloquear números válidos; se extienden solo con rangos confirmados.
  Nota: el ejemplo de la spec muestra "+54 9 ••• •••• 4821"; se respeta RT-03 (solo prefijo de país y últimos 4), sin el 9.

DEC-11 — Reenvío manual reutiliza la fila del destinatario
  Decisión: el `unique(alert_id, user_profile_id, channel)` es una guarda deliberada anti-duplicado (RF-16): NO se relaja. "Reenviar" rota la clave de idempotencia y devuelve la MISMA fila a `pending` (se limpian `failure_*`, `provider_message_id`), incrementa `retry_count`, setea `last_retry_at`/`last_retry_by_user_id`, y despacha `DeliverAlertJob`. El fallo previo queda en el log (`Log::info` con `failure_code` anterior y usuario). Elegibles: `failed` o `suppressed` con códigos `rate_limited|quiet_hours`; no elegibles: `opted_out`, `no_longer_applicable`, `duplicate`.
  Alternativa descartada: nueva fila por intento (requiere cambiar el unique y rompe `ChannelFallbackService::alreadyAttempted`).

DEC-12 — Selección de plantilla por payload + bandera
  Decisión: `TaskDueComposer::effectiveVersion(payload)` devuelve 2 solo si `config('notifications.templates.program_task_due_v2') === true` (env `WHATSAPP_TASK_DUE_V2_ENABLED`, default `false`) Y `payload.contextVersion >= 2`; si no, 1. El preview llama a la misma función, por lo que muestra el texto de la versión realmente activa. El `contextVersion` se escribe SIEMPRE (con bandera apagada también), así el reconciliador puede enriquecer los pendientes antes de encender la bandera.
  Siempre activos (NO dependen de la bandera v2): el revalidador de envío, la compuerta de `PhoneValidator`, la supresión de alertas canceladas y el payload `contextVersion: 2`. La bandera solo elige la plantilla (v1/v2) que se renderiza; apagarla no desactiva ninguna de estas protecciones. Cubierto por `ProgramTaskDueRevalidatorTest::test_with_the_v2_flag_off_...`.
  Footer: `Respondé BAJA para dejar de recibir avisos.` (43 caracteres; Meta limita el footer a 60 y no admite variables). El texto del pie sugerido en la spec (65 caracteres) NO entra.
  Cuerpo v2: termina en texto estático (Meta puede rechazar plantillas cuyo cuerpo termina en variable; el cuerpo propuesto en la spec terminaba en `{{8}}`). Se agrega etiqueta "Indicaciones:" y una línea final estática. Ver catálogo en PR-3.

DEC-13 — Texto libre: saneo en render, sin límite duro en servidor
  Decisión: `WhatsappVariableSanitizer::clean(string $v, int $max, string $fallback = '')` (aplicado a TODAS las variables, no solo a `{{8}}`): reemplaza `\r \n \t` y caracteres de control por espacio, colapsa espacios repetidos a uno, `trim`, recorte multibyte a `$max` con "...", vacío -> `$fallback`. Límites: nombre destinatario 60, empresa 60, protocolo(+grupo) 90, cliente 60, establecimiento 60, tarea 120, "Cuándo" 80, texto libre 200 (fallback "Sin indicaciones adicionales."). NO se agrega `max:200` en los FormRequest de protocolo: `protocol_task_alerts.message` es `TEXT` y hay mensajes existentes más largos que quedarían bloqueados al re-guardar. El frontend muestra contador con aviso suave.

DEC-14 — Copy en el frontend por código, en archivo de constantes del módulo
  Decisión: `front/src/modules/programs/constants/alert-copy.ts` (mismo patrón que `core/constants/require-confirmation-copy.ts`), no `$t`. Es un desvío de la regla "siempre i18n", pero los locales `es` no tienen el módulo y todo `programs` está hardcodeado; migrar el módulo entero excede esta fase. Las claves por `code` quedan listas para moverlas a `i18n/locales/es/programs.ts`.

DEC-15 — H4 por job encolado y red de seguridad nocturna
  Decisión: `ProtocolService::update` encola un `ReconcileProgramAlertsJob` por programa activo que usa el protocolo (`->afterCommit()`, aislado por programa, `tries=3`). Red de seguridad: `programs:reconcile-alerts --stale-only` agendado a diario (el cron de prod ya ejecuta `schedule:run`; no hay SSH, así que es el único mecanismo de "regenerar en lote" disponible sin acceso a consola) más el botón "Recalcular avisos" por programa.
  Válvula de seguridad: el reconciliador aborta un programa (sin cambios) si la reconciliación cancelaría más de la mitad de sus alertas `pending` en un trigger no manual (`protocol_updated`, `nightly`), lo loguea como `error` y deja `out_of_sync` visible. Kill switch: `config('notifications.reconciler.enabled')` (env `ALERTS_RECONCILER_ENABLED`, default true) para los triggers automáticos.

DEC-16 — Producción sin SSH: SQL antes que código, expand/contract
  Decisión: cada migración de esquema/datos tiene su script espejo en `docs/prod-scripts/` (patrón del script existente `2026-10-12_link-program-managers-to-establishments.sql`: diagnóstico, backup, secciones, idempotente) que además inserta la fila en `migrations`. Orden en prod: (1) correr los scripts SQL, (2) desplegar el código. Todo es aditivo y nullable, así que el código viejo funciona con el esquema nuevo (rollback de código seguro). El comando de creación de plantillas (Kapso/Twilio) solo llama a la API del proveedor: se ejecuta desde la máquina del dev con las credenciales de prod, no requiere SSH.
  El deploy de código NO ejecuta `migrate` (supuesto a confirmar con el usuario; si sí lo ejecutara, los scripts son igualmente idempotentes porque insertan la fila en `migrations`).

DEC-17 — H8 (cascade del historial) fuera de alcance, con propuesta
  Decisión: no se cambia el FK de `alert_recipients.user_profile_id` en esta fase (cambiar constraints en prod por phpMyAdmin es de riesgo medio). Propuesta para la iteración siguiente: columna nullable + `nullOnDelete` + `recipient_name` snapshot. Queda en Pendientes.

## Orden de entrega (PRs)

Dependencias: "-> N" significa "después de N". Todo es aditivo; el SQL de producción precede al despliegue del PR indicado.

| PR | Contenido | Capa | Depende de | Migración + SQL prod | Tamaño aprox. |
|---|---|---|---|---|---|
| PR-0 | Hotfix H1 (listener) + tests | BE | — (OTRO agente) | no | — |
| PR-1 | Esquema de trazabilidad, enums, H2 (job tolera destinatario inexistente), dispatcher con CAS, guarda de alerta cancelada | BE | PR-0 | `2026_10_13_000001` + `2026-10-13_fase3-01-alerts-traceability.sql` (incluye backfill de `failure_code`) | ~350 |
| PR-2 | Clasificación de fallos, `PhoneValidator/Masker`, persistir `destination_hint`/`rendered_body`/`template_version`, alerta operativa | BE | PR-1 | no | ~400 |
| PR-3 | Catálogo versionado + v2 + footer + comandos Kapso/Twilio (`--only`) + config; NO activa nada | BE | PR-1 (paralelizable) | no | ~300 |
| PR-4 | Proyección unificada (`ProgramAlertProjector`) y refactor de `ProgramService::projectTargetTasks` + listener usa el proyector | BE | PR-0 | no | ~400 |
| PR-5 | Reconciliador (plan/apply), payload v2, soft-cancel, auditoría, integración en `ProgramService`, listener delgado | BE | PR-1, PR-4 | `2026_10_13_000002` (tabla de auditoría) + `2026-10-13_fase3-02-program-alert-reconciliations.sql` | ~450 (dividir si pasa) |
| PR-6 | Revalidación al enviar (I4), cancelación de programa y `ProgramManagerLinkService` vía reconciliador | BE | PR-5 | no | ~250 |
| PR-7 | Advertencias + preview (alta/edición) + diff + `warnings` en store/update | BE | PR-5 | no | ~450 |
| PR-8 | Endpoint de avisos del programa + permiso `programs.alerts.read` | BE | PR-2, PR-7 | `2026_10_13_000003` + `2026-10-13_fase3-03-programs-alerts-read-permission.sql` | ~400 |
| PR-9 | Reenviar y Recalcular (BE) | BE | PR-8 | no | ~250 |
| PR-10 | Plantilla v2 en builder/composer, sanitizador, email/push con mismo contenido, bandera | BE | PR-3, PR-5 | no | ~350 |
| PR-11 | H4: propagación por job, conteo de programas activos, comando `programs:reconcile-alerts`, agenda nocturna | BE | PR-5 | no | ~300 |
| PR-12 | FE pestaña "Avisos" (solo lectura, estados vacíos, responsive) | FE | PR-8 | no | ~400 |
| PR-13 | FE reenviar/recalcular, banner de advertencias/desalineado, flags en "Cronograma" | FE | PR-9, PR-12 | no | ~300 |
| PR-14 | FE panel "Revisá los avisos", confirmación de críticas, diff, "Ver mensaje", "Guardar sin resumen" | FE | PR-7 | no | ~450 |
| PR-15 | FE protocolo: confirmación "se usa en N programas" + contador 200 | FE | PR-11 | no | ~150 |

Camino crítico externo: PR-3 -> aprobación de la plantilla v2 en Meta/Twilio (minutos a ~48 h). Conviene mergear PR-3 y crear la plantilla apenas esté listo.

## Modelo de datos

### Migración `2026_10_13_000001_add_traceability_columns_to_alerts_and_alert_recipients.php` (PR-1)

`alerts`:
- `cancelled_at` TIMESTAMP NULL
- `cancel_reason` VARCHAR(40) NULL `->comment('AlertCancelReason enum value')`
- `logical_key` CHAR(40) NULL, `unique('alerts_logical_key_unique')` `->comment('sha1 of program|type|target|task-alert|scheduled_at; NULL when cancelled or legacy')`
- `status` sigue siendo `string` (se agrega el valor `cancelled`; sin cambio de DDL).

`alert_recipients`:
- `failure_code` VARCHAR(40) NULL `->comment('FailureCode enum value; failure_reason is internal detail')`
- `destination_hint` VARCHAR(120) NULL `->comment('Masked destination used at send time')`
- `rendered_body` TEXT NULL
- `template_version` SMALLINT UNSIGNED NULL
- `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0
- `last_retry_at` TIMESTAMP NULL
- `last_retry_by_user_id` BIGINT UNSIGNED NULL (sin FK: el usuario puede borrarse; solo auditoría)

`down()`: `dropUnique('alerts_logical_key_unique')` y luego `dropColumn` de todo (en ese orden, requerido por SQLite). Reversible sin pérdida de datos previos.

Sin backfill de `logical_key`: los avisos legacy quedan en NULL y el reconciliador los "adopta" (PR-5). Sin `NOT NULL` nuevos. Índices: solo el unique de `logical_key` (ver DEC-07).

### Backfill de `failure_code` (PR-1, dentro del `up()` de la misma migración, con `DB::table()->where()->update()` portable, sin SQL crudo)

Solo patrones determinísticos: `suppressed` + `failure_reason in (opted_out, no_longer_applicable, duplicate, rate_limited, quiet_hours)` -> mismo código; `failed` + canal `whatsapp` + `failure_reason like 'El perfil % no tiene un contacto whatsapp habilitado para alertas'` -> `no_whatsapp_contact`; idem `email` -> `no_email_contact`. Lo demás queda NULL (la API devuelve `unknown` para `failed` sin código, RF-04). Idempotente (`whereNull('failure_code')`). `down()` no revierte datos (la columna se descarta).

### Migración `2026_10_13_000002_create_program_alert_reconciliations_table.php` (PR-5)

`program_alert_reconciliations`: `id`, `guid` char(36) unique, `program_id` FK cascade, `user_id` BIGINT UNSIGNED NULL (sin FK), `trigger` VARCHAR(40) (`program_created|program_updated|program_cancelled|managers_unlinked|protocol_updated|manual_rebuild|nightly|command`), `counts` JSON (`kept, updated, created, cancelled{by_reason}, untouched_dispatched, skipped_past, adopted`), `aborted` BOOLEAN default false, `created_at`. Índice `(program_id, created_at)`. Modelo `App\Models\ProgramAlertReconciliation` (`HasGuid`, `$hidden=['id']`, sin `updated_at`). Solo se escribe cuando hubo algún cambio o un aborto (no se llena de filas vacías).

### Migración `2026_10_13_000003_add_programs_alerts_read_permission.php` (PR-8)

Copia el patrón de `2026_09_29_000004_add_programs_managers_read_permission.php` con `PERMISSION = 'programs.alerts.read'` y `SOURCE_PERMISSIONS = ['programs.read']`, excluyendo roles cuyo nombre empiece con `client-` (defensa: la spec dice que los roles de cliente no acceden). Resultado esperado: `vet`, `vet-administrative`, `vet-assistant`, `super-admin`. Además: agregar `'programs.alerts.read'` a `back/database/seeders/ProgramPermissionsSeeder.php` (lista `$permissions`, ya asigna a vet/vet-administrative/vet-assistant y a super-admin vía `syncPermissions(Permission::all())`).

### Scripts SQL de producción (`docs/prod-scripts/`)

Formato idéntico al existente (cabecera con problema/qué hace/antes de correr, SECCIÓN 1 diagnóstico read-only, SECCIÓN 2 cambio en transacción donde el motor lo permita, idempotente, "EXPORTAR BACKUP ANTES").

1. `2026-10-13_fase3-01-alerts-traceability.sql`
   - 1A preflight: `SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('alerts','alert_recipients') AND COLUMN_NAME IN (...)` (debe devolver 0 filas; si devuelve alguna, saltar el `ADD COLUMN` correspondiente).
   - 2A `ALTER TABLE alerts ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL, ADD COLUMN cancel_reason VARCHAR(40) NULL DEFAULT NULL, ADD COLUMN logical_key CHAR(40) NULL DEFAULT NULL, ADD UNIQUE KEY alerts_logical_key_unique (logical_key);`
   - 2B `ALTER TABLE alert_recipients ADD COLUMN failure_code VARCHAR(40) NULL, ADD COLUMN destination_hint VARCHAR(120) NULL, ADD COLUMN rendered_body TEXT NULL, ADD COLUMN template_version SMALLINT UNSIGNED NULL, ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN last_retry_at TIMESTAMP NULL DEFAULT NULL, ADD COLUMN last_retry_by_user_id BIGINT UNSIGNED NULL;` (DDL de MySQL hace commit implícito: ejecutar sentencia por sentencia).
   - 2C backfill de `failure_code` con los mismos patrones (`UPDATE ... WHERE failure_code IS NULL`), con conteo previo/posterior.
   - 2D `INSERT INTO migrations (migration, batch) SELECT '2026_10_13_000001_add_traceability_columns_to_alerts_and_alert_recipients', COALESCE(MAX(batch),0)+1 FROM migrations WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '...');`
   - Sección ROLLBACK comentada (DROP INDEX/COLUMN + DELETE de la fila de `migrations`).
2. `2026-10-13_fase3-02-program-alert-reconciliations.sql`: `CREATE TABLE IF NOT EXISTS program_alert_reconciliations (...)` + FK + índice + fila en `migrations`.
3. `2026-10-13_fase3-03-programs-alerts-read-permission.sql`: `INSERT ... SELECT UUID() ... WHERE NOT EXISTS` en `permissions` (guid `UUID()`, `guard_name='web'`); `INSERT` en `role_has_permissions` desde los roles que tienen `programs.read` y no son `client-%`, con `NOT EXISTS`; fila en `migrations`. **Caché de Spatie**: tras el SQL hay que invalidar la caché de permisos (si no, tarda hasta 24 h): como no hay SSH, la sentencia depende del driver (`CACHE_STORE`): con `database` -> `DELETE FROM cache WHERE `key` LIKE '%spatie.permission.cache%';` (verificar prefijo con `SELECT `key` FROM cache LIMIT 20` antes); con `file`, borrar `storage/framework/cache/data` por FTP/panel. Incluir el diagnóstico `SELECT r.name, p.name FROM role_has_permissions ...` para validar.
4. Diagnósticos de apoyo (sin cambios, en el mismo script 01 o aparte): cantidad de `protocol_task_alerts.message` con más de 200 caracteres; cantidad de `alerts` `task_due` `pending` con `JSON_EXTRACT(payload,'$.contextVersion') IS NULL` (sirve para decidir cuándo retirar v1).

## Cambios en BACKEND

Notación: `[PR-n]` indica en qué PR entra cada ítem. Todas las rutas y clases nuevas en español de negocio, identificadores en inglés.

### A. Enums y soporte [PR-1 / PR-2]

Crear:
- `back/app/Notifications/Enums/FailureCode.php` (string-backed): `no_whatsapp_contact`, `no_email_contact`, `invalid_number`, `opted_out`, `provider_unreachable`, `provider_rejected`, `service_misconfigured`, `no_longer_applicable`, `duplicate`, `rate_limited`, `quiet_hours`, `unknown`. Método `isRetryable(): bool` (true: `no_whatsapp_contact`, `no_email_contact`, `invalid_number`, `provider_unreachable`, `provider_rejected`, `service_misconfigured`, `rate_limited`, `quiet_hours`, `unknown`).
- `back/app/Notifications/Enums/AlertCancelReason.php` (string-backed, los 6 valores de DEC-04).
- `back/app/Notifications/Enums/AlertStatus.php` (string-backed `pending|dispatched|cancelled`) usado de aquí en adelante en código nuevo; los `'pending'` literales existentes no se tocan fuera de los archivos modificados.

Modificar:
- `back/app/Notifications/Models/Alert.php`: `$fillable` += `cancelled_at, cancel_reason, logical_key`; casts `cancelled_at => datetime`, `cancel_reason => AlertCancelReason::class`. Scope `scopeActive` (`status in pending,dispatched`).
- `back/app/Notifications/Models/AlertRecipient.php`: `$fillable` += `failure_code, destination_hint, rendered_body, template_version, retry_count, last_retry_at, last_retry_by_user_id`; casts `failure_code => FailureCode::class`, `last_retry_at => datetime`.

### B. Endurecimiento del pipeline de envío [PR-1]

`back/app/Notifications/Scheduling/DispatchDueAlerts.php`
- Antes: itera alertas `pending` vencidas, despacha jobs y recién entonces `update(status=dispatched)`.
- Después: por cada alerta, `DB::transaction`: `$claimed = Alert::whereKey($alert->id)->where('status','pending')->update(['status'=>'dispatched'])`; si `$claimed !== 1` -> `continue`; luego `AlertRecipient::where('alert_id',$alert->id)->where('status','pending')->get()` (consulta posterior al claim, para ver los destinatarios que el reconciliador haya confirmado antes) y `DeliverAlertJob::dispatch($id)->afterCommit()`. Ya no usa `->with('recipients')`. Alertas `cancelled` nunca entran (el filtro `status='pending'` las excluye).

`back/app/Notifications/Jobs/DeliverAlertJob.php`
- H2: `AlertRecipient::with('alert.subject')->find($this->recipientId)`; si `null` -> `Log::info('DeliverAlertJob: destinatario inexistente, se descarta')` y `return` (sin excepción, sin reintentos). Aplica también a `failed()` (ya usa `find`).
- Si `$recipient->alert->status === 'cancelled'` -> `Suppressed` + `failure_reason='no_longer_applicable'` + `failure_code=NoLongerApplicable`; `return` (sin fallback).
- (PR-2 y PR-6 agregan pasos; el orden final del `handle` se documenta en PR-6).

### C. Clasificación de fallos y trazabilidad de envío [PR-2]

Crear:
- `back/app/Notifications/Services/FailureClassifier.php`: `fromProviderCode(string $provider, string|int|null $code): FailureCode` leyendo `config('notifications.failure_map')`; código no mapeado -> `Unknown` + `Log::warning('FailureClassifier: código de proveedor sin mapear', [provider, code])`. `fromThrowable(Throwable)`: `NotificationConfigurationException` -> `ServiceMisconfigured`; `RecipientContactNotFoundException` según canal; resto -> `ProviderUnreachable`.
- `back/app/Notifications/Support/PhoneValidator.php`, `PhoneMasker.php` y `back/config/phone_rules.php` (DEC-10).
- `back/app/Notifications/Services/ServiceMisconfiguredNotifier.php`: `report(AlertRecipient, Throwable)`: `Log::critical(...)` siempre; si `config('notifications.ops_email')` (env `NOTIFICATIONS_OPS_EMAIL`) está seteado, envía un mail simple limitado a 1 por hora con `Cache::add('notif:misconfigured', 1, 3600)`. Sin infraestructura nueva.

Modificar:
- `back/config/notifications.php`: agregar `failure_map`, `ops_email`, `locale_by_country_iso` (`['default' => 'es']`), `high_volume_per_day` (3), `reconciler.enabled`, `templates.program_task_due_v2` (bandera). Tabla de mapeo inicial (A VERIFICAR contra la documentación vigente de cada proveedor antes de mergear; lo no mapeado cae en `unknown` y se loguea):

| Proveedor | Códigos | `FailureCode` |
|---|---|---|
| Meta (Kapso) `error.code` | 131026, 133010 | `invalid_number` |
| Meta | 131047, 131049, 131050, 131051, 131053, 131056 | `provider_rejected` |
| Meta | 132000, 132001, 132005, 132007, 132012, 132015, 132016, 131008, 131009, 131005, 131031, 190, 100, 368 | `service_misconfigured` |
| Twilio `RestException::getCode()` / `ErrorCode` webhook | 21211, 21214, 21217, 21614, 63003, 63024 | `invalid_number` |
| Twilio | 21610 | `opted_out` |
| Twilio | 63016, 63049, 63038, 63018, 63032 | `provider_rejected` |
| Twilio | 20003, 20404, 63007, 21606, 21608 | `service_misconfigured` |
| cualquiera | 5xx/timeouts agotados en cola | `provider_unreachable` |

- `back/app/Notifications/Data/DeliveryResult.php`: `failed(string $reason, ?FailureCode $code = null)` y propiedad `failureCode`.
- `back/app/Notifications/Gateways/Kapso/KapsoWhatsappGateway.php` y `.../Twilio/TwilioWhatsappGateway.php`: al devolver `DeliveryResult::failed`, clasificar con el código de proveedor (Meta `error.code`; Twilio `$e->getCode()`). El `failure_reason` crudo se conserva (interno).
- `back/app/Notifications/Services/RecordDeliveryStatus.php::applyFailure`: setear `failure_code` clasificando `message.errors.0.code` (Kapso) o `ErrorCode` del payload de Twilio (verificar la clave exacta en `TwilioWebhookController`/`ProcessWhatsappWebhookEventJob`).
- `back/app/Notifications/Jobs/DeliverAlertJob.php` — cada `update()` de fallo/supresión agrega `failure_code`; antes de `$gateways->for(...)->send()` persiste en la fila `destination_hint` (`PhoneMasker` sobre `$recipientDto->phone` o email enmascarado), `rendered_body` (via `WhatsappTemplateCatalog::render` para `TemplateContent`, cuerpo del `EmailContent`; `null` para push) y `template_version` (de `TemplateContent->version`), incluso si el envío falla. Pre-chequeo: canal WhatsApp y `!PhoneValidator::isPlausible($phone)` -> `Failed` `invalid_number` + `$fallback->attempt()` (sin llamar al proveedor). `NotificationConfigurationException` -> `ServiceMisconfiguredNotifier::report`. `failed()` -> `provider_unreachable`. Supresión del pipeline: `SuppressionReason` -> `FailureCode` (mismo valor).
- `back/app/Notifications/Services/ChannelFallbackService.php`: sin cambios funcionales. Se documenta que el fallback a email no se dispara si el destinatario `Suppressed`.

### D. Proyección unificada [PR-4]

Crear en `back/app/Services/ProgramAlerts/`:
- `Data/ProjectionInput.php`: `readonly` con `Vet $vet`, `?string $programGuid`, `Protocol $protocol` (con `tasks.alerts`), `list<TargetInput> $targets` (`ref`, `?guid`, `date`), `Collection<UserProfile> $managers` (con `role`, `user`, `contacts`), `CarbonImmutable $now`, `string $locale`. `static fromProgram(Program, CarbonImmutable $now)`.
- `Data/ProgramProjection.php`: `items: list<ProjectedAlert>` (ordenados por `scheduledAtUtc`), `tasksWithoutAlerts: list<{protocolTaskGuid, description}>`, `timezone`, helper `byTarget()`.
- `Data/ProjectedAlert.php`: `targetRef`, `?targetGuid`, `targetDate`, `protocolTaskGuid`, `taskDescription`, `taskDate`, `taskTime`, `important`, `protocolTaskAlertGuid`, `message`, `requireConfirmation`, `roles`, `scheduledAtLocal` (`LocalDateTime`), `scheduledAtUtc`, `isPast`, `multiGroup`, `recipients: list<ProjectedRecipient{UserProfile profile, string role}>`, `?logicalKey`, y `toPayload(): array` (payload v2, DEC-06).
- `Data/LocalDateTime.php`: `{iso, date, time, weekday, weekday_short, label}` (DEC-08), `toArray()`.
- `LogicalKey.php`: `static for(string $programGuid, AlertType $type, string $targetGuid, string $taskAlertGuid, CarbonInterface $scheduledAtUtc): string`.
- `ProgramAlertProjector.php`:
  ```php
  final class ProgramAlertProjector {
      public function project(ProjectionInput $in): ProgramProjection;
  }
  ```
  Algoritmo (idéntico al del listener y a `projectTargetTasks`, ahora único): `taskDate = DateOffset::apply(target.date, task.days_offset, task.time_of_day)`; `alertDate = DateOffset::apply(taskDate, alert.offset_days, alert.time_of_day)`; `scheduledAtLocal = CarbonImmutable::parse(alertDate->toDateString().' '.alert.time, $vet->timezone())`; `scheduledAtUtc = ->utc()`; `isPast = scheduledAtUtc <= $now` (un único `now` por proyección); destinatarios = `managers` cuyo `role->name` está en `alert.roles`. La proyección NO descarta nada: marca (`isPast`, `recipients=[]`). El que decide qué se persiste es el reconciliador.
- `back/app/Support/LocalizedDate.php`: `weekday(CarbonInterface, string $locale)`, `label(...)`, `relativeDays(CarbonInterface $taskDate, CarbonInterface $sendDate): string` (0 "hoy", 1 "mañana", 2 "pasado mañana", >2 "en N días", -1 "ayer", <-1 "hace N días"; para PR-10).

Modificar:
- `back/app/Services/ProgramService.php`: inyectar `ProgramAlertProjector`; reescribir `projectTargetTasks` para construir `ProjectionInput::fromProgram($program, now)` y mapear cada ítem a la estructura actual de `projected_tasks` (sin romper el contrato: `protocol_task_guid, description, occurs_on, occurs_at, important, alerts[{protocol_task_alert_guid, occurs_on, occurs_at, roles, message, require_confirmation, recipients[]}]`) AGREGANDO `scheduled_at_local` (objeto `LocalDateTime`), `timing` (`future|past`) y `skip_reason` (`null|no_recipients`). Se agrega parámetro opcional `findByGuidForVet(string $guid, int $vetId, bool $withProjection = true)`; `update`/`cancel`/PDF lo llaman con `false` para no pagar la proyección al mutar (optimización opcional dentro del mismo PR).
- `back/app/Listeners/GenerateProgramTaskDueAlertsListener.php` (ya parchado por el otro agente): reemplazar el cálculo propio (`DateOffset::apply`, zona, `isPast`, filtro de roles) por llamadas al proyector, conservando el comportamiento H1 (cambio neutro; los tests del hotfix siguen verdes). El listener todavía borra/crea; esa lógica la sustituye PR-5.
- `back/app/Http/Resources/V1/ProgramTargetResource.php`: sin cambios (`tasks` ya es `projected_tasks`).
- `projectTasksForPdf` queda como está (usa `DateOffset` directo; ya comparte la regla de fecha).

### E. Reconciliador [PR-5]

Crear en `back/app/Services/ProgramAlerts/`:
- `Data/ReconcilePlan.php`: `adopt[]`, `keep[]`, `update[]` (payload cambiado), `create[]`, `cancel[]` (`{alert, reason}`), `recipientChanges[]` (`add/reactivate/suppress` por alerta), `untouchedDispatched[]`, `skippedPast[]`; `isEmpty()`.
- `Data/ReconcileReport.php`: contadores del plan aplicado + `warnings` (lista de `Warning`) + `aborted`.
- `ProgramAlertReconciler.php`:
  ```php
  final class ProgramAlertReconciler {
      public function plan(Program $program, ?CarbonImmutable $now = null): ReconcilePlan;        // sin efectos
      public function apply(Program $program, ReconcilePlan $plan, string $trigger, ?int $userId = null): ReconcileReport;
      public function reconcile(Program $program, string $trigger, ?int $userId = null, ?CarbonImmutable $now = null): ReconcileReport; // transacción + lock + plan + apply
      public function isStillValid(Alert $alert, int $userProfileId): bool;                       // para PR-6
  }
  ```
  `reconcile()`: `DB::transaction` -> `Program::whereKey()->lockForUpdate()->first()` -> recargar relaciones (`targets`, `protocol.tasks.alerts`, `managers.role|user|contacts`, `vet.country`) -> `plan()` -> chequeo de válvula (DEC-15) -> `apply()` -> `ProgramAlertReconciliation::create(...)` si hubo cambios -> `Log::info('program.alerts.reconciled', [...])`.

  Algoritmo de `plan()` (invariantes):
  1. Si el programa está cancelado (`cancelled_at`), `desired = []` y toda alerta `pending` se cancela con `program_cancelled`.
  2. `desired` = ítems del proyector con `recipients` no vacíos y `logicalKey` no nulo (el programa persistido siempre tiene guids de grupo).
  3. `existing` = alertas `task_due` del programa con status `pending|dispatched`, indexadas por `logical_key`.
  4. Adopción de legacy: para cada ítem sin match por clave, buscar una alerta `logical_key IS NULL` con `payload.protocolTaskAlertGuid` y `scheduled_at` iguales (1 a 1, la primera no usada) y asignarle `logical_key` + payload v2 (`adopt`). Aplica a `pending` y `dispatched` (una legacy ya enviada cuenta como "ya existe").
  5. Por ítem deseado: existe `pending` -> `keep` (aunque esté vencida: I1), `update` si el payload difiere, y sincronizar destinatarios (agregar los que faltan con `AlertRecipientFactory::ensureForManagers`, reactivar suprimidos, suprimir los de managers quitados o sin rol); existe `dispatched` -> `untouchedDispatched` (no se agregan destinatarios: "no recibe los pasados"); no existe y `isPast` -> `skippedPast` (se informa `ALERT_IN_PAST`); no existe y futuro -> `create`.
  6. Alertas `pending` cuya clave no está en `desired` -> `cancel` con motivo inferido: programa cancelado `program_cancelled`; el `targetGuid` ya no existe en el programa `group_removed`; la tarea/alerta de protocolo ya no pertenece al protocolo actual (o `payload.protocolGuid` != protocolo actual) `protocol_changed`; mismo grupo y misma alerta con otra fecha `rescheduled`; sin destinatarios `recipient_removed`; resto `superseded`. Las vencidas inválidas también se cancelan con motivo (nunca "descarte silencioso").
  7. `apply()`: cada cancelación/actualización/alta de destinatario sobre una alerta existente se ejecuta con CAS (`where status='pending'`); `0` filas -> pasa a `untouchedDispatched`. Alta de alertas: `Alert::create([... 'logical_key', 'payload'=>$item->toPayload(), 'vet_id'])` + `ensureForManagers`. Idempotencia: una segunda corrida inmediata debe devolver plan vacío.
- `back/app/Notifications/Services/AlertRecipientFactory.php`: agregar `ensureForManagers(Alert $alert, iterable $managers): array{created:int, reactivated:int}` idempotente por `(alert, profile, channel)` (respeta el filtro de Push por suscripción); `createForManagers` queda igual (lo usan creación/cancelación/PDF compartido).
- `back/app/Models/ProgramAlertReconciliation.php` (ver migración).

Modificar:
- `back/app/Notifications/Data/Payloads/ProgramTaskPayload.php`: agregar propiedades nullable con default `null` (`programTargetGuid, protocolTaskGuid, protocolGuid, targetDate, taskDescription, taskDate, taskTime, multiGroup, contextVersion`). Compatible con payloads legacy (Spatie Data ignora claves ausentes si tienen default).
- `back/app/Services/ProgramService.php`: inyectar `ProgramAlertReconciler`; `create`/`update` reemplazan `event(new ProgramTargetsChangedEvent(...))` por `$report = $this->reconciler->reconcile($program, 'program_created'|'program_updated', auth()->id())` y anotan `$program->alert_report = $report`/`$program->alert_warnings = $report->warnings` (el warnings real llega en PR-7; en PR-5 queda `[]`). `cancel` no cambia (lo resuelve PR-6).
- `back/app/Listeners/GenerateProgramTaskDueAlertsListener.php`: cuerpo = `$this->reconciler->reconcile($event->program, 'managers_unlinked')`. Se conserva el nombre de clase y el evento.
- `back/app/Repositories/ProgramRepositoryEloquent.php::findActiveProgramsWithStaleAlertRecipients`: sin cambios (ya filtra `status='pending'`, por lo que ignora las canceladas); agregar test que lo confirme. Nota: el archivo está modificado en el working tree por otro trabajo; no pisar esos cambios.

Tests (`back/tests/Feature/ProgramAlertReconcilerTest.php`, renombrando/ampliando el archivo del hotfix `GenerateProgramTaskDueAlertsListenerTest.php`, que queda como suite de regresión): los 5 tests H1 pasan, con DOS ajustes de aserción por soft-cancel/supresión: (a) `test_overdue_alert_that_is_no_longer_valid_is_removed` pasa a contar solo alertas `status != cancelled` (la alerta queda como `cancelled` con `cancel_reason=group_removed`); (b) `test_overdue_alert_drops_recipients_that_are_no_longer_managers_but_keeps_the_rest` valida `status=pending` del restante y `suppressed/no_longer_applicable` del quitado en lugar de ausencia de la fila. Nuevos casos:
- Idempotencia: 2 corridas -> 0 cambios, mismos guids de alerta.
- Editar solo animales de un grupo: las 10 alertas conservan guid, 0 altas/bajas (RF-11).
- Cambiar fecha de un grupo de dos: solo se reprograman las de ese grupo (`rescheduled`); las del otro intactas.
- Reprogramar a una fecha y volver: la clave se reutiliza (la cancelada tiene `logical_key=NULL`); si la original ya estaba `dispatched`, no se recrea.
- Cambiar `protocol_id`: se cancelan las `pending` con `protocol_changed`, `dispatched` intactas.
- Agregar responsable: recibe pendientes futuros, no las `dispatched`. Quitar responsable: suprimido; re-agregar: reactivado; quitar a todos: alerta `cancelled` `recipient_removed`.
- Adopción de legacy (payload sin `programTargetGuid`): se mantiene la alerta (mismo guid), se completa clave y payload; dos grupos con misma fecha adoptan 1 a 1.
- Scheduler caído (5 vencidas) + edición: ninguna se pierde (I1).
- CAS (I2): `plan()`, luego marcar una alerta `dispatched` por fuera, `apply()` -> queda `dispatched`, contada `untouched_dispatched`, sin excepción.
- Fecha pasada sin alerta previa: `skipped_past` y `ALERT_IN_PAST`; con alerta `pending` vencida: se conserva.
- Válvula: trigger `protocol_updated` que cancelaría >50% -> `aborted=true`, sin cambios.
- `ProgramService::create` crea alertas con `logical_key` y payload v2; no dispara `ProgramTargetsChangedEvent`.
- Multi-tenant: dos vets, la reconciliación de un programa no toca alertas de otro.

### F. Revalidación al enviar y cancelación [PR-6]

Crear:
- `back/app/Notifications/Contracts/AlertRevalidator.php`: `type(): AlertType` y `check(Alert $alert, AlertRecipient $recipient): ?FailureCode` (devuelve `NoLongerApplicable` o `null`).
- `back/app/Notifications/Revalidators/ProgramTaskDueRevalidator.php`: usa `ProgramAlertReconciler::isStillValid`. Reglas: alerta `cancelled`; programa cancelado; el perfil ya no es responsable o su rol ya no está en `roles` de la alerta del protocolo; si el payload es v2: el grupo sigue existiendo con la misma fecha y la clave recalculada con datos vivos coincide con `logical_key` (misma tarea/alerta, misma `scheduled_at`); si es legacy: programa activo + perfil sigue siendo responsable + la `protocolTaskAlertGuid` sigue en el protocolo vigente.
- `back/app/Notifications/Registries/AlertRevalidatorRegistry.php` (tag `alert.revalidators` en `NotificationServiceProvider`, igual que builders).

Modificar:
- `back/app/Notifications/Jobs/DeliverAlertJob.php` — orden final del `handle`: (1) `find` (H2) (2) idempotencia `status===Pending` (3) alerta `cancelled` (4) revalidador del tipo -> si devuelve código: `Suppressed` + `no_longer_applicable`, SIN fallback (5) `toDto()` + pre-chequeo de teléfono (PR-2) (6) builder (7) pipeline de políticas (8) gateway. La revalidación corre también en reintentos y en el intento de correo de respaldo (corrige H3).
- `back/app/Notifications/NotificationServiceProvider.php`: registrar el tag y el registry.
- `back/app/Listeners/HandleProgramCancelledListener.php`: reemplazar el borrado duro por `ProgramAlertReconciler::reconcile($program, 'program_cancelled')` (cancela las `pending` con `program_cancelled`) y luego crear la alerta `program.cancelled` como hoy. Las alertas ya `dispatched` con destinatarios en cola/reintento quedan frenadas por el revalidador.
- `back/app/Services/ProgramManagerLinkService.php::regenerateAlerts`: llamar a `ProgramAlertReconciler::reconcile($program, 'managers_unlinked')` directo en lugar de `event(...)` (misma transacción y mismo aislamiento por programa).

Tests: `back/tests/Unit/Notifications/ProgramTaskDueRevalidatorTest.php` (cada regla) y ampliar `DeliverAlertJobTest`: (a) programa cancelado entre dispatch y reintento -> `Suppressed/no_longer_applicable` y `Queue::assertNothingPushed` (sin fallback a correo); (b) responsable quitado -> idem; (c) fecha de grupo cambiada -> la alerta `dispatched` vieja queda suprimida; (d) alerta legacy válida -> se envía; (e) `HandleProgramCancelledListener` cancela `pending` con motivo y no borra filas.

### G. Advertencias y preview [PR-7]

Crear en `back/app/Services/ProgramAlerts/`:
- `RecipientDiagnostics.php`: `diagnose(Collection<UserProfile> $profiles): array<profileGuid, RecipientDiagnosis>` con `whatsapp: {status: ok|missing|invalid_number, masked: ?string, phone: ?string}`, `email_fallback: bool` (`config('notifications.fallback.whatsapp')` contiene `email` y el perfil tiene un contacto de correo `use_for_alerts`), `opted_out: bool`. Precarga contactos de todos los perfiles y consulta `OptOut` una sola vez (`whereIn('phone', ...)`, canal `whatsapp`).
- `Data/Warning.php` y `ProgramAlertWarningService.php` (DEC-09). Umbral `config('notifications.high_volume_per_day')`.
- `ProgramAlertPreviewService.php`: `preview(ProjectionInput $in, ?Program $existing): ProgramPreview` (proyecta, diagnostica, renderiza mensajes con `TaskDueComposer`, arma `summary`, `items`, `warnings` y, si hay `$existing`, `diff` usando `ProgramAlertReconciler::plan()` sobre el programa persistido más las diferencias de entrada (grupos con `guid` existente y nueva fecha, grupos nuevos por `target_ref`, managers/protocolo propuestos). Tope `config('notifications.preview_max_items', 1000)` -> `truncated:true` conservando `summary` y `warnings` completos.
- `back/app/Notifications/Templates/TaskDueComposer.php` (versión mínima en este PR: solo v1, interfaz lista para v2 en PR-10): `compose(TaskDueContext $ctx, string $recipientName, int $version): ComposedMessage{version, variables, text}`; `effectiveVersion(?ProgramTaskPayload): int` (siempre 1 hasta PR-10). `TaskDueContext` es un DTO propio (`vetName, clientName, establishmentName, protocolName, multiGroup, targetDate, taskDescription, taskDate, taskTime, scheduledAtLocal, freeText, timezone, locale`). `ProgramTaskDueMessageBuilder` se refactoriza para delegar en el compositor (mismas variables v1 `{1: nombre, 2: protocolo, 3: mensaje}`; test de catálogo intacto).
- `back/app/Http/Requests/Programs/PreviewProgramRequest.php`: `extends StoreProgramRequest`; `rules()` = padre + `targets.*.guid` nullable uuid + `targets.*.target_ref` nullable string max 36; `animals` opcional e ignorado. En edición (ruta con `{guid}`), valida además que cada `targets.*.guid` pertenezca al programa del tenant. Mensajes en español.
- `back/app/Http/Controllers/V1/ProgramPreviewController.php`: `preview(PreviewProgramRequest)` (alta) y `previewForProgram(PreviewProgramRequest)` (edición; 404 si el programa no es del tenant; 422 `not_editable` si está cancelado). Lee `$request->route('guid')` (patrón de `ProgramController`, por el problema de binding posicional). Resuelve guids a ids igual que `ProgramController::resolveGuidsToIds` (extraer ese método a un trait/servicio compartido `ResolvesProgramGuids` para no duplicarlo; el `Controller` base ya es `ApiResponseTrait`).
- `back/app/Http/Resources/V1/ProgramPreviewResource.php` y `WarningResource` (o arrays tipados).

Modificar:
- `back/app/Http/Resources/V1/ProgramResource.php`: agregar `warnings` y `reconciliation` con `$this->when(isset($this->alert_warnings), ...)` (mismo mecanismo que `projected_tasks`).
- `back/app/Services/ProgramService.php`: poblar `alert_warnings` con `ProgramAlertWarningService::build(..., WarningMode::Save, ...)` y `alert_report` (resumen: `kept, rescheduled, created, cancelled, already_sent_not_modified` con las leyendas de RF-11).
- `back/routes/api/programs.php`: ver "Rutas API".

Tests (`back/tests/Feature/ProgramPreviewControllerTest.php`, `back/tests/Unit/ProgramAlerts/ProgramAlertWarningServiceTest.php`, `.../ProgramAlertProjectorTest.php`, `.../RecipientDiagnosticsTest.php`):
- Paridad (RT-01): para un mismo programa, `preview(will_send)` == alertas creadas por `reconcile` (mismas fechas UTC, mismos destinatarios); y `projected_tasks` del detalle usa las mismas fechas.
- El preview no escribe: conteo de `alerts`, `alert_recipients`, `program_alert_reconciliations` y `Queue::assertNothingPushed()` antes/después.
- Un caso por cada código de la spec; agregación >5 con `count/items`; `critical` vs `warning` (correo de respaldo -> `RECIPIENT_EMAIL_ONLY`); `HIGH_VOLUME_FOR_RECIPIENT` con umbral configurable; `DUPLICATE_TARGET_DATE`; `TASK_WITHOUT_ALERTS`.
- RF-08: alerta de hoy 09:00 con "ahora 14:00" -> `ALERT_IN_PAST`; alerta a +30 s -> válida.
- Multi-tenant: client/establishment/protocol/manager de otro vet -> 422; programa de otro vet -> 404; permisos (`programs.create`/`programs.update`) -> 403 sin ellos.
- Edición: `diff` con casos (mantiene, reprograma de->a, cancela, agrega, ya enviados no modificados con fechas previas); grupos nuevos por `target_ref`.
- Teléfonos enmascarados en todo el payload (regex sobre el JSON: ningún dígito consecutivo >4 salvo prefijo).
- `truncated:true` por encima del tope; store/update devuelven `warnings` y `reconciliation`.
- Rendimiento: test de humo con 10 grupos x 8 tareas x 3 alertas x 4 personas (sin aserción de tiempo; se mide manualmente).

### H. Avisos del programa (lectura) [PR-8]

Crear:
- `back/app/Services/ProgramAlerts/AlertLineStateResolver.php`: `resolve(Alert, Collection<AlertRecipient> $attemptsOfProfile, CarbonImmutable $now): AlertLineState` (`state`, `bucket`, `failure_code`, `fallback_used`, `primary`). Reglas (en este orden): alerta `cancelled` -> `cancelled`; algún intento `read` -> `read`, luego `delivered`, luego `sent` (WhatsApp tiene prioridad sobre correo; si solo el correo salió -> `sent` con `fallback_used=true`); algún intento `pending` -> `scheduled` si la alerta es `pending` y `scheduled_at > now`, si no `in_process`; todos terminales: el intento WhatsApp manda (`failed` -> `failed`, `suppressed` -> `not_sent`); `failure_code` NULL en `failed` -> `unknown`. Buckets: `scheduled` (`scheduled|in_process`), `sent` (`sent|delivered|read`), `problem` (`failed|not_sent`), `cancelled`.
- `back/app/Services/ProgramAlerts/ProgramAlertLineQuery.php`: `paginate(Program $program, int $vetId, array $filters, int $page, int $perPage): array{lines, total, counters}` (DEC-07). Filtra siempre por `alerts.vet_id = $vetId` Y `subject_type='program'` Y `subject_id = $program->id`; tipos `program.task_due|created|cancelled|pdf_shared`; excluye `channel='push'` (DU-03). Orden: `scheduled` por `scheduled_at` asc; resto desc. En "Todos": programados primero. Carga contactos solo de los perfiles de la página para el estado de contacto en vivo (RF-13).
- `back/app/Services/ProgramAlerts/ProgramAlertsService.php`: orquesta `findProgram -> lines -> meta (counters, warnings modo Tab, timezone, out_of_sync, multi_group, program_cancelled_at, last_reconciled_at)`. `out_of_sync = !ProgramAlertReconciler::plan($program)->isEmpty()` (solo programas activos).
- `back/app/Http/Controllers/V1/ProgramAlertController.php`: `index(IndexProgramAlertsRequest)`.
- `back/app/Http/Requests/Programs/IndexProgramAlertsRequest.php`: `status` in `all,scheduled,sent,problem,cancelled`; `profile` uuid; `target` uuid; `type` in los 4 tipos; `page` int>=1; `per_page` int 1..50 (default 25). Mensajes en español.
- `back/app/Http/Resources/V1/ProgramAlertLineResource.php`.

Modificar:
- `back/app/Traits/ApiResponseTrait.php::makeSuccessPagination`: nuevo parámetro opcional `?array $meta = null` que, si viene, se agrega como clave `meta` (retrocompatible).
- `back/database/seeders/ProgramPermissionsSeeder.php`: ver Permisos.

Tests (`back/tests/Feature/ProgramAlertControllerTest.php`, `back/tests/Unit/ProgramAlerts/AlertLineStateResolverTest.php`): tabla de estados (todas las combinaciones relevantes de WhatsApp/correo/`pending`/vencida/`cancelled`, incluido "WhatsApp falló + correo enviado" = una fila `sent` con `fallback_used`); `sent` sin `delivered_at` no se presenta como entregado; `read` y `delivered` según dato; el JSON nunca contiene `failure_reason`, `confirmed_at`, `id`, números sin enmascarar ni claves de proveedor; paginación 25 y filtros (estado, persona, grupo, tipo) en servidor; contadores respetan persona/grupo/tipo pero no estado; 404 para programa de otro vet (y para guid inexistente); 403 sin `programs.alerts.read`; rol `client-*` -> 403; alertas de otro vet con el mismo `subject_id` no aparecen; push excluido; históricos sin `failure_code` -> `unknown`; programa cancelado muestra `cancelled`; `meta.warnings` modo Tab (IN_PAST como `info`).

### I. Reenviar y Recalcular [PR-9]

Crear:
- `back/app/Services/ProgramAlerts/ProgramAlertRetryService.php`: `retry(Program $program, string $recipientGuid, int $vetId, ?int $userId): AlertRecipient` en transacción con `lockForUpdate` de la fila. Valida en este orden y lanza `ProgramAlertRetryException(reason)`: `not_found` (el destinatario no es de una alerta del programa/tenant -> 404), `program_cancelled`, `alert_cancelled`, `not_retryable` (estado/`failure_code` no elegible o alerta no `task_due`... se permite también `program.created|cancelled|pdf_shared` solo si el programa está activo), `opted_out` (nunca reenvía a quien se dio de baja: se recomprueba contra `OptOut` en vivo), `already_delivered` (existe hermano — misma alerta y perfil, cualquier canal — en `sent|delivered|read`; el copy "Ya llegó por correo" lo arma el frontend cuando el hermano es el correo), `recently_retried` (`last_retry_at` < 10 min), `contact_still_missing` (para `no_whatsapp_contact`/`invalid_number` sin corrección: se re-diagnostica con `RecipientDiagnostics`). Si pasa: DEC-11 (rotar `idempotency_key`, `pending`, limpiar fallo, `retry_count++`, `last_retry_*`), `DeliverAlertJob::dispatch($id)->afterCommit()`, `Log::info('program.alert.retry', [...])`. Si la alerta estaba `pending` aún (programada) no corresponde: `not_retryable`.
- `back/app/Http/Controllers/V1/ProgramAlertController.php` (mismo controller): `retry(Request)` y `rebuild(Request)`.
- Rebuild: `ProgramAlertReconciler::reconcile($program, 'manual_rebuild', $userId)`; 422 `not_editable` si el programa está cancelado; idempotente; respuesta con `reconciliation` y `warnings`.

Tests: matriz completa de `retry` (cada razón de 422; caso feliz deja la fila `pending`, rota la clave y encola exactamente un job); doble clic -> segundo 422 `recently_retried`; hermano de correo `sent` -> `already_delivered`; baja registrada -> `opted_out` aun si el código era retryable; `Queue::fake()`; otro tenant -> 404; sin `programs.update` -> 403. `rebuild`: idempotente (segunda llamada = 0 cambios), corrige un `out_of_sync` fabricado (alerta borrada a mano), `not_editable`, auditoría en `program_alert_reconciliations` con `user_id`.

### J. Plantilla WhatsApp v2 [PR-3 catálogo y comandos; PR-10 builder]

PR-3 (no activa nada):
- `back/app/Notifications/Templates/WhatsappTemplateCatalog.php`: agregar `versions(): array<string, array<int, array{body, examples, footer?:string}>>` y `latestVersion(AlertType)`, `for(AlertType $type, int $version = 1)`, `exampleVariables(AlertType, int $version = 1)`, `render(AlertType, int $version, array $variables): string` (sustituye `{{n}}` y agrega el pie al final con salto de línea doble). `definitions()` conserva su forma actual (v1) para los comandos y tests existentes. Entrada v2 de `program.task_due`:
  ```
  body:
  Hola {{1}}, te escribe {{2}} por el programa "{{3}}" ({{4}}, {{5}}).
  Tarea: {{6}}
  Cuándo: {{7}}
  Indicaciones: {{8}}
  Este es un aviso automático de SAV.
  footer: Respondé BAJA para dejar de recibir avisos.
  examples: ['María', 'Agropecuaria ID', 'Superovulación P500', 'Cliente SA', 'Estancia La Invernada', 'Colecta de embriones', 'mañana, jueves 24/09/2026 a las 09:00', 'Traer termo y camiseta de colecta.']
  ```
  Contingencia si Meta rechaza por ratio texto/variables: fusionar `{{4}}` y `{{5}}` en una sola variable "cliente / establecimiento" (7 variables). Es un cambio local al catálogo, al compositor y al test que los ancla.
- `back/app/Notifications/Data/TemplateContent.php`: agregar `public int $version = 1` y `templateKey(): string` (`type->value` para v1, `type->value.'@v'.version` para otras).
- `back/app/Notifications/Gateways/Kapso/KapsoWhatsappGateway.php::templateFor` y `.../Twilio/TwilioWhatsappGateway.php::resolveContentSid`: resolver por `$content->templateKey()` (el resto igual).
- `back/config/notifications.php`: `kapso.templates['program.task_due@v2'] = ['name' => env('KAPSO_TEMPLATE_PROGRAM_TASK_DUE_V2','sav_program_task_due_v2'), 'language' => env('KAPSO_TEMPLATE_LANGUAGE','es')]`; `twilio.templates['program.task_due@v2'] = env('TWILIO_TEMPLATE_PROGRAM_TASK_DUE_V2')`; `templates.program_task_due_v2 => (bool) env('WHATSAPP_TASK_DUE_V2_ENABLED', false)`. Idioma por país: la configuración sigue siendo global ("es"); DU-10 (pt-BR) queda abierto, pero el locale de fechas ya se lee de `locale_by_country_iso`.
- `back/app/Console/Commands/KapsoCreateTemplatesCommand.php`: (a) iterar `type@vN` (nueva fuente `WhatsappTemplateCatalog::versions()`), manteniendo el invariante 1:1 catálogo/config; (b) agregar el componente `FOOTER` (`['type'=>'FOOTER','text'=>$footer]`) cuando la definición lo trae; (c) opción `--only=program.task_due@v2` (repetible) para no reintentar las plantillas ya creadas (Meta rechaza nombres duplicados); (d) `--dry-run` imprime el payload completo incluido el footer. Hoy el comando solo crea el componente BODY.
- `back/app/Console/Commands/TwilioCreateTemplatesCommand.php`: agregar `'program.task_due@v2' => ['env'=>'TWILIO_TEMPLATE_PROGRAM_TASK_DUE_V2','friendly_name'=>'sav_program_task_due_v2']`; `twilio/text` no tiene footer, por lo que el pie se concatena al final del body (`body . "\n\n" . footer`); opción `--only=` igual que Kapso; imprime la línea de `.env` resultante.
- Tests: `WhatsappTemplateCatalogTest` (variables v2 contiguas 1..8; cada placeholder con ejemplo; cuerpo <= 1024 caracteres con ejemplos y con el peor caso de longitudes de DEC-13; footer <= 60 y sin variables; el cuerpo no empieza ni termina con variable; `render()`); `KapsoCreateTemplatesCommandTest` (payload v2 con FOOTER, `--only`, invariante config/catálogo); nuevo `TwilioCreateTemplatesCommandTest` (dry-run v2, footer concatenado); tests de gateways con `TemplateContent(version: 2)` resolviendo la clave correcta y lanzando `TemplateNotConfiguredException` si falta.

PR-10 (builder/compositor v2 detrás de la bandera):
- `back/app/Notifications/Templates/TaskDueComposer.php`: implementar v2. Variables: `1` nombre (fallback "equipo"), `2` `Vet->name`, `3` protocolo + (si `multiGroup`) `" - grupo del dd/mm/aaaa"`, `4` cliente, `5` establecimiento, `6` descripción de la tarea, `7` "`{relativo}, {día} {dd/mm/aaaa} a las {HH:mm}`" con hora de la TAREA (`taskTime`), relativo calculado entre `taskDate` y el día local de `scheduled_at` (no "ahora": estable en reintentos), `8` texto libre. Todo pasa por `WhatsappVariableSanitizer` (DEC-13; nuevo `back/app/Notifications/Support/WhatsappVariableSanitizer.php`). Nunca pide responder ni confirmar.
- `back/app/Notifications/Builders/ProgramTaskDueMessageBuilder.php`: arma `TaskDueContext` desde el payload + datos vivos del programa (`vet`, `client`, `establishment`, `protocol`); `effectiveVersion` decide. Email: asunto `Recordatorio: {tarea} — {protocolo}` y cuerpo con las mismas líneas (cliente, establecimiento, tarea, cuándo, texto). Push: `body` con protocolo, establecimiento, tarea y cuándo. (Push no se muestra en la pestaña, DU-03, pero conserva el contenido mínimo.)
- `AlertRecipient.rendered_body` y `template_version` se llenan con lo que ya hace PR-2.
- Tests: `back/tests/Unit/Notifications/TaskDueComposerTest.php` (ejemplo de la spec renderizado literal; relativos 0/1/2/n/-1/-n; la fecha concreta siempre presente; grupo solo con `multiGroup`; hora de la tarea y no la del aviso; texto libre con saltos/tabs/espacios/`>200`/vacío; locale explícito independiente de `app.locale`; `effectiveVersion` con bandera apagada/encendida y payload legacy/v2; el texto del preview == el que construye el builder con el mismo contexto), `WhatsappTemplateCatalogTest` agrega `ProgramTaskDue` v2 al `builderProvider` con la bandera encendida, y test de compatibilidad: alerta legacy (payload sin `contextVersion`) con bandera encendida -> v1.

Orden operativo con el proveedor (también en Rollback/QA): ver "Despliegue".

### K. H4 y comando [PR-11]

Crear:
- `back/app/Jobs/ReconcileProgramAlertsJob.php` (`ShouldQueue`, `tries=3`, `backoff [60,300]`, `__construct(int $programId, string $trigger)`): carga el programa (si no existe o está cancelado, termina); llama al reconciliador; excepción -> `Log::error` con programa y se relanza para reintento (el fallo de un programa no frena a los demás porque cada uno es un job).
- `back/app/Console/Commands/ReconcileProgramAlertsCommand.php`: `programs:reconcile-alerts {--program=guid} {--stale-only} {--dry-run} {--trigger=command}`; recorre programas activos con `chunkById(100)`, aislamiento por programa (`try/catch`), imprime tabla de conteos (patrón de `ReconcileUnlinkedProgramManagersCommand`), `--dry-run` usa solo `plan()`, `--stale-only` procesa los programas con `plan` no vacío. Sale con `FAILURE` si algún programa falló.
- `routes/console.php`: `Schedule::command('programs:reconcile-alerts --stale-only --trigger=nightly --no-ansi')->dailyAt('03:30')->withoutOverlapping()->appendOutputTo(storage_path('logs/scheduler.log'))`.

Modificar:
- `back/app/Services/ProtocolService.php::update`: después de `syncTasks`, `Program::where('protocol_id',$protocol->id)->whereNull('cancelled_at')->pluck('id')->each(fn ($id) => ReconcileProgramAlertsJob::dispatch($id, 'protocol_updated')->afterCommit())`. Nuevo método público `countActiveProgramsForProtocol(Protocol): int`. `countProgramsForProtocol` (stub) NO se toca (ver Riesgos).
- `back/app/Http/Resources/V1/ProtocolResource.php` y `VetProtocolResource.php`: agregar `active_programs_count` (solo cuando el recurso es de detalle/edición; calculado con una consulta, nunca en listados).
- Las vet-protocol y admin-protocol `update` ya pasan por `ProtocolService::update`: ambos caminos propagan (un protocolo global editado por superadmin reconcilia programas de varios vets; cada job opera con el scope del programa).
- Tests: editar la descripción de una tarea de un protocolo en uso -> los avisos `pending` del programa se actualizan (payload) tras ejecutar el job (`Queue::fake()` + `ReconcileProgramAlertsJob::dispatchSync`), los `dispatched` no; programa cancelado no se reconcilia; el fallo de un programa no afecta a otro; el comando (`--dry-run` no escribe, `--stale-only`, aislamiento de errores, `--program`); `active_programs_count` correcto y sin N+1.

### L. Rutas API

Archivo `back/routes/api/programs.php` (prefijo `v1/vets/{vet}/programs`, middleware `['auth:sanctum','vet.tenant']`):

| Método | Path | Controller@acción | Permiso | PR |
|---|---|---|---|---|
| POST | `/preview` | `ProgramPreviewController@preview` | `can:programs.create` | PR-7 |
| POST | `/{guid}/preview` | `ProgramPreviewController@previewForProgram` | `can:programs.update` | PR-7 |
| GET | `/{guid}/alerts` | `ProgramAlertController@index` | `can:programs.alerts.read` | PR-8 |
| POST | `/{guid}/alerts/{recipientGuid}/retry` | `ProgramAlertController@retry` | `can:programs.update` | PR-9 |
| POST | `/{guid}/alerts/rebuild` | `ProgramAlertController@rebuild` | `can:programs.update` | PR-9 |

Declarar `/preview` y `/{guid}/alerts/rebuild` antes de rutas con parámetros que puedan capturarlos (`rebuild` antes de `/{guid}/alerts/{recipientGuid}/retry` no colisiona por ser distinto verbo/segmentos, pero se deja el orden explícito). Todos los controllers leen `{guid}` con `$request->route('guid')` (patrón existente por el binding posicional con `{vet}`).

### M. Permisos Spatie

- Nuevo: `programs.alerts.read` (guard `web`).
- Se otorga por la migración `2026_10_13_000003` (y su SQL de prod) a los roles que hoy tienen `programs.read` excluyendo `client-*`: `vet`, `vet-administrative`, `vet-assistant`, `super-admin`.
- `ProgramPermissionsSeeder` lo incluye (instalaciones nuevas; `givePermissionTo` es aditivo; `WithoutModelEvents` ya aplica en seeders y el `guid` se setea explícito con `Str::uuid()`).
- `programs.create`/`programs.update` se reutilizan para preview/reenviar/recalcular (spec).
- Frontend: la pestaña usa `can('programs.alerts.read')` vía `usePermission()`; acciones con `<PermissionGuard permission="programs.update">`.

### N. Contratos

#### GET `/v1/vets/{vet}/programs/{guid}/alerts`

Query: `status` (`all` por defecto), `profile`, `target`, `type`, `page`, `per_page` (25).

Respuesta 200 (el interceptor del front ya desenvuelve `{success,data}`):
```json
{
  "data": [{
    "key": "<alertGuid>:<profileGuid>",
    "alert_guid": "…", "type": "program.task_due",
    "scheduled_at_local": {"iso":"2026-09-24T09:00:00-03:00","date":"2026-09-24","time":"09:00","weekday":"jueves","weekday_short":"jue","label":"jue 24/09/2026 09:00"},
    "scheduled_at_utc": "2026-09-24T12:00:00Z",
    "recipient": {"profile_guid":"…","name":"María Pérez","role":"client-manager"},
    "channel": "whatsapp",
    "state": "sent", "bucket": "sent",
    "failure_code": null, "cancel_reason": null,
    "fallback": {"used": true, "email_state": "sent"},
    "destination_masked": "+54 ••• •••• 4821",
    "sent_at": "…", "delivered_at": null,
    "task": {"protocol_task_guid":"…","description":"Colecta de embriones","task_date":"2026-09-24","task_time":"09:00"},
    "message": "Traer termo…", "rendered_body": null,
    "group": {"target_guid":"…","target_date":"2026-09-23","label":"grupo del 23/09/2026"},
    "contact": {"status":"ok","masked":"+54 ••• •••• 4821"},
    "attempts": [{"recipient_guid":"…","channel":"whatsapp","state":"failed","failure_code":"invalid_number","destination_masked":"…","sent_at":null,"delivered_at":null,"attempts":1}],
    "retry": {"allowed": true, "recipient_guid":"…", "blocked_reason": null}
  }],
  "current_page": 1, "last_page": 1, "per_page": 25, "total": 1,
  "meta": {
    "counters": {"scheduled": 0, "sent": 1, "problem": 0, "cancelled": 0, "total": 1},
    "warnings": [],
    "timezone": "America/Argentina/Buenos_Aires",
    "out_of_sync": false, "multi_group": false,
    "program_cancelled_at": null, "last_reconciled_at": null
  }
}
```
Notas: `state` ∈ `scheduled|in_process|sent|delivered|read|failed|not_sent|cancelled`; `contact` y `retry.blocked_reason` solo para líneas relevantes; `rendered_body` solo si está guardado (históricos: `null`); `channel` es el canal efectivo (`whatsapp` | `email_fallback`); `group` puede apuntar a un grupo ya borrado (se arma desde el payload). Errores: 404 programa ajeno/inexistente (`makeNotFound`), 403 sin permiso, 422 de validación de filtros.

#### POST `/v1/vets/{vet}/programs/preview` y `/{guid}/preview`

Request: igual a store/update sin animales:
```json
{ "client_id":"uuid", "establishment_id":"uuid", "protocol_id":"uuid",
  "targets":[{"guid":"uuid|null","target_ref":"0","target_date":"2026-09-23"}],
  "manager_profile_ids":["uuid"] }
```
Respuesta 200:
```json
{
  "generated_at": "…", "timezone": "America/Argentina/Buenos_Aires", "truncated": false,
  "template": {"task_due_version": 1},
  "program": {"company_name":"…","client":{"guid":"…","name":"…"},"establishment":{…},"protocol":{…},"technique":{…},
              "managers":[{"guid":"…","name":"…","role":"…"}]},
  "summary": {"alerts_total":24,"alerts_will_send":22,"alerts_wont_send":2,"recipients_total":3,"groups_count":2,
              "window":{"from":"2026-09-21","to":"2026-10-30"}},
  "items": [{
    "target_key":"…","target_guid":null,"target_ref":"0","target_date":"2026-09-23",
    "protocol_task_guid":"…","task_description":"…","task_date":"…","task_time":"09:00",
    "protocol_task_alert_guid":"…","scheduled_at_local":{…LocalDateTime…},"scheduled_at_utc":"…",
    "will_send": true, "skip_reason": null, "roles":["client-manager"], "require_confirmation": false,
    "free_text": "Traer termo y camiseta de colecta.",
    "recipients":[{"profile_guid":"…","name":"…","role":"…","channel":"whatsapp","destination_masked":"+54 ••• •••• 4821",
                   "contact_status":"ok","will_send":true,"skip_reason":null,"rendered_message":"Hola María, …"}]
  }],
  "warnings": [{"code":"ALERT_IN_PAST","severity":"critical","context":{"target_ref":"0","protocol_task_alert_guid":"…","scheduled_at_local":{…},"count":1}}],
  "diff": null
}
```
`skip_reason` de ítem: `null|in_past|no_recipients`; de destinatario: `null|no_whatsapp_contact|invalid_number|opted_out`; `contact_status`: `ok|email_only|missing|invalid_number|opted_out`. `diff` (solo edición):
```json
{ "kept": 8, "updated": 1, "created": 4, "cancelled": 2,
  "rescheduled": [{"target_guid":"…","protocol_task_alert_guid":"…","task_description":"…","from":{…LocalDateTime…},"to":{…}}],
  "cancelled_items": [{"task_description":"…","scheduled_at_local":{…},"reason":"group_removed"}],
  "sent_not_modified": [{"task_description":"…","scheduled_at_local":{…},"target_date_previous":"2026-09-23","recipients_count":3}],
  "protocol_changed": false }
```
Errores: 422 validación (`makeError` con `errors`), 422 `reason: not_editable` (programa cancelado), 404 programa ajeno, 403 permiso. El preview no persiste, no encola ni notifica.

#### store / update (existentes)

`ProgramResource` agrega `warnings: Warning[]` (autoritativo, calculado sobre lo persistido) y `reconciliation: {kept, rescheduled, created, cancelled, already_sent_not_modified}`. Códigos HTTP sin cambios. Sin bloqueo en servidor (DU-01).

#### POST `/{guid}/alerts/{recipientGuid}/retry` -> 200 `{ line: <ProgramAlertLine> }`; 422 con `makeError(['reason'=>…], msg, 422)` y `reason` ∈ `program_cancelled|alert_cancelled|not_retryable|opted_out|already_delivered|recently_retried|contact_still_missing`; 404 si el destinatario no es del programa/tenant.

#### POST `/{guid}/alerts/rebuild` -> 200 `{ reconciliation: {...}, warnings: [...] }`; 422 `not_editable`.

#### Warning

```json
{ "code":"RECIPIENT_WITHOUT_WHATSAPP", "severity":"critical",
  "context": { "target_guid|target_ref": "…", "protocol_task_guid":"…", "protocol_task_alert_guid":"…",
               "profile_guid":"…", "name":"…", "role":"…", "roles":["…"],
               "scheduled_at_local": {…LocalDateTime…}, "count": 3, "email_fallback": false,
               "items": [ {"target_ref":"0","scheduled_at_local":{…}} ] } }
```
Solo guids y datos presentables. El copy vive en el frontend por `code`. Códigos y severidades exactamente los de RF-07.

## Cambios en FRONTEND

Rutas y permisos: la pestaña vive dentro de la página existente `VetProgramDetailPage.vue` (ruta `vet-programs-detail`); la pestaña activa se refleja en `?tab=schedule|alerts`. No hay rutas nuevas.

### Archivos a crear (módulo `front/src/modules/programs/`)

- `types/program-alerts.types.ts`: `LocalDateTime`, `AlertState`, `AlertBucket`, `FailureCode`, `AlertCancelReason`, `ProgramAlertLine`, `ProgramAlertAttempt`, `ProgramAlertsMeta`, `ProgramAlertsFilters`, `WarningCode`, `WarningSeverity`, `ProgramWarning`, `ProgramPreview`, `PreviewItem`, `PreviewRecipient`, `PreviewDiff`, `ReconciliationSummary`. Sin `any`.
- `api/program-alerts.api.ts`: `listProgramAlertsApi(vetGuid, guid, params)` -> `PaginatedResponse<ProgramAlertLine> & {meta}`; `retryProgramAlertApi(vetGuid, guid, recipientGuid)`; `rebuildProgramAlertsApi(vetGuid, guid)`; `previewProgramApi(vetGuid, payload, guid?)`.
- `constants/alert-copy.ts` (DEC-14): `STATE_LABEL` (Programado, En proceso, Enviado, Entregado, Leído, Con problema, No enviado, Cancelado), `STATE_HINT` ("Aún sin confirmación de entrega" para `sent`; aclaración de privacidad de lectura para `read`), `FAILURE_COPY` por `FailureCode` con la tabla exacta de RF-04 (más copy para `no_email_contact`, `duplicate`, `rate_limited`, `quiet_hours`) y acción asociada (`go_to_contacts` | `retry` | none), `CANCEL_REASON_COPY`, `WARNING_COPY` por `WarningCode` (título, detalle con interpolación de `name/role/count/roles`, severidad), textos de estados vacíos y error (RF-05), `FALLBACK_COPY` ("No llegó por WhatsApp — se envió por correo"). Ningún copy menciona respuesta/confirmación del destinatario.
- `stores/program-alerts-ui.store.ts` (Pinia, solo UI): filtros (`status`, `profile`, `target`, `type`), `page`, filas expandidas. Se resetea al cambiar de programa.
- `composables/useProgramAlerts.ts`: `useQuery` con key `['program-alerts', vetGuid, guid, filters, page]`, `placeholderData` para evitar parpadeo, `enabled` solo si el usuario tiene `programs.alerts.read`.
- `composables/useRetryProgramAlert.ts`, `composables/useRebuildProgramAlerts.ts`: `useMutation` con `invalidateQueries(['program-alerts', vetGuid, guid])` y `['program', vetGuid, guid]`; mapeo de 422 `reason` a mensajes (`recently_retried` -> "Ya se reintentó hace poco. Esperá unos minutos."; `already_delivered`, etc.).
- `composables/useProgramPreview.ts`: `useMutation` de lectura (excepción documentada a "toda mutación invalida": no cambia estado de servidor).
- `composables/useProgramSaveFlow.ts`: orquesta guardar -> preview -> confirmar; estados `idle|loading|ready|error`; `confirmAndSave()`; `saveWithoutPreview()`; expone `requiresAcknowledgement` (hay `critical`) y `acknowledged`.
- `components/tenant/alerts/ProgramAlertsTab.vue` (contenedor), `ProgramAlertsSummaryBar.vue` (contadores + pestañas internas Programados/Enviados/Con problema, con "Todos"), `ProgramAlertsFilters.vue` (`BaseSelect` x4), `ProgramAlertsTable.vue` (desktop, `BaseDataTable` si encaja o `a-table`), `ProgramAlertCard.vue` (móvil, sin scroll horizontal; se elige con `useResponsive().isMobile`), `ProgramAlertStatusTag.vue` (sobre `BaseTag`/`BaseBadge`), `ProgramAlertAttemptsDetail.vue` (detalle expandible con los intentos), `ProgramAlertsOutOfSyncBanner.vue` ("Los avisos programados no coinciden con el plan actual" + "Recalcular avisos"), `ProgramWarningsBanner.vue` ("N avisos de este programa no se van a enviar. Ver detalle."), `ProgramWarningList.vue` (compartida con el panel de preview; agrupa y ordena por severidad, expande `items`).
- `components/tenant/preview/ProgramPreviewPanel.vue` (sobre `BaseDrawer`, ancho ~720; en móvil full width), `ProgramPreviewSummary.vue` (encabezado + resumen en una oración), `ProgramPreviewGroups.vue` (colapsado por grupo; virtualizado/paginado si hay más de 50 ítems), `ProgramPreviewDiff.vue`, `ProgramMessageModal.vue` ("Ver mensaje", sobre `BaseModal`, muestra `rendered_message` literal), `ProgramPreviewError.vue` ("No pudimos generar el resumen" + [Reintentar] [Guardar sin resumen]).

### Archivos a modificar

- `pages/tenant/VetProgramDetailPage.vue`: reemplazar la `BaseCard "Grupos"` por `<a-tabs>`: "Cronograma" (contenido actual) y "Avisos" (solo si `can('programs.alerts.read')`; con `<a-badge dot>` rojo si `meta.counters.problem > 0`). Banner de programa cancelado (RF-05) con la fecha de `cancelled_at`.
- `components/tenant/ProgramTargetsTimeline.vue` (archivo con cambios sin commitear de otros: rebasar): mostrar `scheduled_at_local.label`, tag "Ya pasó" cuando `timing='past'` y "Sin destinatarios para este rol" cuando `skip_reason='no_recipients'`, para que el cronograma no prometa avisos que no existen.
- `pages/tenant/VetProgramFormPage.vue` (con cambios sin commitear: rebasar): `onSubmit` deja de llamar directo a `mutateCreate/mutateUpdate`; llama a `useProgramSaveFlow.start(payload)`, que abre `ProgramPreviewPanel`. Al guardar con éxito: si la respuesta trae `warnings` críticas, navega al detalle con `?tab=alerts` y muestra notificación; si no, vuelve a la lista como hoy.
- `types/program.types.ts`: `ProgramDetail.warnings?: ProgramWarning[]`, `reconciliation?: ReconciliationSummary`; `ProgramProjectedAlert` suma `scheduled_at_local`, `timing`, `skip_reason`.
- `composables/useProgramMutations.ts`: sin cambios de contrato (el `onSuccess` ya invalida `programs` y `program`); agregar `['program-alerts', vetGuid, guid]` a la invalidación de `useUpdateProgram` y `useCancelProgram`.
- `front/src/modules/protocols/components/tenant/VetProtocolFormDrawer.vue` y `.../ProtocolFormDrawer.vue` (con cambios sin commitear: rebasar) [PR-15]: en modo edición, si `active_programs_count > 0` abrir `BaseConfirmDialog` con "Este protocolo se usa en N programas activos. Los avisos pendientes de esos programas se van a actualizar." antes de guardar. `ProtocolAlertFormItem.vue`: contador de caracteres del mensaje (200) con aviso suave (no bloquea; DEC-13).

### Flujos

- Confirmación de críticas: el panel muestra el checkbox "Entiendo que estos avisos no se van a enviar" solo si hay advertencias `critical`; "Confirmar y guardar" queda deshabilitado hasta marcarlo. Sin críticas: un solo clic. Las advertencias del preview se muestran ordenadas por severidad; la respuesta de store/update (autoritativa) reemplaza lo que se mostró (si trae algo nuevo, notificación + redirección a `?tab=alerts`).
- Estados vacíos y error (RF-05): `BaseEmptyState` para sin avisos (con botón "Editar programa" envuelto en `PermissionGuard programs.update`), filtro sin resultados, sin problemas; `BaseErrorState` con "Reintentar".
- "Reenviar": botón por fila con `PermissionGuard programs.update`, habilitado según `retry.allowed`; deshabilitado con motivo ("Ya llegó por correo", etc.) según `blocked_reason`; `BaseConfirmDialog` simple. "Ir a contactos" enlaza a Personal del cliente > Contactos usando `recipient.profile_guid` (ruta existente del módulo `clients`; verificar nombre de ruta al implementar).
- Dato real: "Enviado" muestra la leyenda "Aún sin confirmación de entrega"; "Leído" solo si `state==='read'`; ninguna pantalla muestra `confirmed_at` ni "confirmado".
- Enmascaramiento: el frontend solo muestra `destination_masked`/`contact.masked`.

### Tests frontend (Vitest + `@vue/test-utils`, patrón de `VetProgramFormPage.test.ts`: `vi.mock` de composables)

- `ProgramAlertsTab.test.ts`: contadores; filtros que llaman al API con los parámetros correctos; estados vacío/filtro vacío/error con Reintentar; punto rojo con `problem>0`; tarjetas en móvil; sin permiso `programs.alerts.read` no se monta la pestaña.
- `alert-copy.test.ts`: cada `FailureCode` y `WarningCode` del contrato tiene copy (exhaustividad con `satisfies Record<…>`), y ningún texto contiene "confirm" en el sentido de respuesta del destinatario.
- `ProgramAlertStatusTag.test.ts`: `sent` -> leyenda sin confirmación; `read` vs `delivered`; fallback "se envió por correo".
- `useProgramSaveFlow.test.ts` y `ProgramPreviewPanel.test.ts`: sin críticas un clic guarda; con críticas el botón exige el checkbox; error de preview ofrece "Guardar sin resumen" y guarda; el preview no dispara create/update; la respuesta de guardado con nuevas críticas redirige a la pestaña; diff renderiza los conteos.
- `VetProgramFormPage.test.ts` (existente): adaptar los mocks (`useProgramSaveFlow`) sin perder las pruebas de managers.
- `ProgramTargetsTimeline` (nuevo test chico): tags `Ya pasó` y `Sin destinatarios`.
- Protocolo (PR-15): el diálogo aparece solo con `active_programs_count > 0`; contador de 200 no bloquea.

## Despliegue y orden operativo con el proveedor (RF-20)

1. Correr en phpMyAdmin los scripts SQL 01 y 02 (y 03 antes de PR-8), uno por uno, tras backup. Verificar con las consultas de diagnóstico de cada script.
2. Desplegar PR-1..PR-2 y PR-4..PR-7, PR-9, PR-11 (la bandera `WHATSAPP_TASK_DUE_V2_ENABLED` sigue apagada).
3. Con PR-3 en producción de código (o localmente con credenciales de prod, solo API): `php artisan kapso:create-templates --only=program.task_due@v2` y/o `php artisan twilio:create-templates --only=program.task_due@v2`. Verificar el estado `APPROVED` en el panel de Kapso/Twilio (puede tardar hasta ~48 h).
4. Cargar en `.env` de producción `KAPSO_TEMPLATE_PROGRAM_TASK_DUE_V2` (si cambia el nombre) y/o `TWILIO_TEMPLATE_PROGRAM_TASK_DUE_V2` (contentSid); limpiar caché de configuración.
5. Regenerar pendientes: esperar la reconciliación nocturna (`--stale-only`) o usar "Recalcular avisos" por programa; verificar con el diagnóstico SQL de `contextVersion IS NULL` que no quedan `pending` legacy.
6. Encender `WHATSAPP_TASK_DUE_V2_ENABLED=true`; probar con un programa de prueba hacia un número del equipo (preview + envío real); revisar `rendered_body` y `template_version=2` en la pestaña/DB.
7. Retirar v1 solo cuando no haya `pending` legacy ni jobs en cola de la versión anterior.

## Orden de implementación (paso a paso)

PR-1
1. Migración `2026_10_13_000001` + backfill + modelos/enums (`FailureCode`, `AlertCancelReason`, `AlertStatus`) + `$fillable`/casts.
2. `DispatchDueAlerts` con claim por CAS + tests (`DispatchDueAlertsTest` ampliado: sin doble fan-out, ignora `cancelled`, corrida repetida despacha una vez).
3. `DeliverAlertJob`: `find` (H2) y guarda de alerta `cancelled` + tests.
4. Script `docs/prod-scripts/2026-10-13_fase3-01-alerts-traceability.sql` y verificación de equivalencia con la migración (probar ambos contra una copia).

PR-2
1. `PhoneValidator`, `PhoneMasker`, `config/phone_rules.php` + tests por país (AR 54/9, MX, CL, CO, PE, BR; casos límite).
2. `FailureClassifier` + `config('notifications.failure_map')` + `DeliveryResult::failed(code)`.
3. Gateways Kapso/Twilio y `RecordDeliveryStatus` clasifican; tests con `Http::fake` y excepciones.
4. `DeliverAlertJob`: códigos en cada rama, pre-chequeo de teléfono, `destination_hint`/`rendered_body`/`template_version`, `ServiceMisconfiguredNotifier`; tests.

PR-3
1. Catálogo versionado + `render` + entrada v2 + footer + tests de catálogo.
2. `TemplateContent->version/templateKey` + gateways + tests.
3. Config (claves v2 y bandera) + comandos Kapso/Twilio con footer y `--only` + tests.
4. Mergear y arrancar de inmediato la creación/aprobación de la plantilla (paso 3 del despliegue).

PR-4
1. DTOs, `LocalDateTime`, `LogicalKey`, `LocalizedDate`, `ProgramAlertProjector` + tests unitarios del proyector (fechas, zona horaria, `time_of_day` before/after, hora local -> UTC, DST de Chile, `isPast`, roles, `multiGroup`).
2. Refactor de `ProgramService::projectTargetTasks` (contrato extendido) + test de contrato del detalle (campos viejos intactos, campos nuevos presentes).
3. Listener parchado usa el proyector (tests del hotfix en verde).

PR-5
1. Releer listener/tests del hotfix. Migración + modelo de auditoría + script SQL 02.
2. `ProgramTaskPayload` extendido; `AlertRecipientFactory::ensureForManagers`.
3. `ReconcilePlan/Report` + `ProgramAlertReconciler` (primero `plan()` con tests; luego `apply()`; luego `reconcile()` con lock/transacción/auditoría/válvula).
4. `ProgramService` create/update llaman al reconciliador; listener delgado. Ajustar los 2 asserts del hotfix y agregar los tests nuevos.

PR-6
1. `AlertRevalidator`, registry, `ProgramTaskDueRevalidator` + tests.
2. `DeliverAlertJob` orden final; `HandleProgramCancelledListener` y `ProgramManagerLinkService` vía reconciliador + tests.

PR-7
1. `PhoneValidator` ya existe; `RecipientDiagnostics` + `ProgramAlertWarningService` + tests.
2. `TaskDueComposer` (v1) + refactor de `ProgramTaskDueMessageBuilder` (test de catálogo intacto).
3. `ProgramAlertPreviewService` (+ diff) + `PreviewProgramRequest` + `ProgramPreviewController` + resource + rutas.
4. `ProgramResource` con `warnings`/`reconciliation`; `ProgramService` los completa. Tests de paridad y de no-escritura.

PR-8
1. Migración de permiso + seeder + script SQL 03.
2. `AlertLineStateResolver`, `ProgramAlertLineQuery`, `ProgramAlertsService`, request, resource, controller, ruta, `makeSuccessPagination($meta)`. Tests.

PR-9
1. `ProgramAlertRetryService` + excepción + `retry` y `rebuild` en el controller + rutas + tests.

PR-10
1. `WhatsappVariableSanitizer`; `TaskDueComposer` v2; builder con contexto/payload; email/push; bandera. Tests (incluido preview == builder).

PR-11
1. `ReconcileProgramAlertsJob`, comando, agenda, `ProtocolService::update` encola, `active_programs_count` en recursos. Tests.

PR-12..PR-15 (FE, en este orden): tipos/api/constants/store/composables -> pestaña y componentes -> estados vacíos/errores -> responsive -> tests; luego acciones y banners; luego panel de preview y flujo de guardado; luego protocolo.

## Riesgos y consideraciones

- R1 Conflicto con el hotfix H1: el otro agente modifica el mismo listener y creó el test de regresión. PR-5 debe rebasar sobre su commit; no reescribir su suite, solo los 2 asserts descritos. Si su fix cambió la forma de las alertas (por ejemplo agregó columnas), reconciliar antes de codificar.
- R2 Plantilla v2 depende de aprobación externa y puede ser rechazada por las reglas de Meta (variable al final del cuerpo, ratio texto/variables, footer <= 60). Mitigado con diseño estático al final y plan B de fusión de variables; la bandera mantiene v1 hasta que esté aprobada. Verificar vigencia de límites con Meta/Twilio antes de mergear PR-3.
- R3 Concurrencia reconciliador/dispatcher: el diseño CAS es verificable en SQLite, pero `lockForUpdate` del programa solo se prueba en MySQL real. Antes de producción, correr una prueba manual con dos procesos (staging MySQL) editando mientras corre `alerts:dispatch-due`.
- R4 Alertas legacy sin `programTargetGuid`: la adopción por `(protocolTaskAlertGuid, scheduled_at)` puede ser ambigua con dos grupos de igual fecha (se adopta 1 a 1 en orden estable). Si no matchea, la alerta legacy `pending` se cancela con motivo y se recrea con clave (sin pérdida porque la fecha se conserva). La primera reconciliación masiva debe correrse con `--dry-run` y revisarse (la válvula de >50% protege contra errores del algoritmo).
- R5 Reconciliación nocturna automática = cambios sin acción del usuario. Mitigaciones: solo `--stale-only`, válvula de 50%, kill switch `ALERTS_RECONCILER_ENABLED`, auditoría en `program_alert_reconciliations`, logs.
- R6 Validación de teléfono laxa: un falso positivo de `invalid_number` impediría un envío válido (el pre-chequeo bloquea). Por eso los rangos son laxos y el pre-chequeo comparte el validador con el preview; los rangos nuevos se agregan solo con evidencia.
- R7 DST y hora inexistente/ambigua (Chile, otros): Carbon desplaza las horas inexistentes hacia adelante; el preview muestra la hora local resultante. Hay un test del proyector; no se resuelve la ambigüedad de horas repetidas (se toma la primera).
- R8 Zona horaria del vet vs establecimiento (H7/DU-08): se mantiene la del vet y la API devuelve `timezone`; el panel/pestaña la muestran. Revisar cuando haya establecimientos en otro país.
- R9 `rendered_body` guarda nombres y texto del mensaje (datos personales mínimos): solo se expone en la pestaña del programa. Los programas no se borran (solo se cancelan) y `alerts` no cascada desde `programs`, por lo que la retención "igual al historial del programa" no requiere purga hoy; una política de retención explícita queda pendiente.
- R10 `ProtocolService::countProgramsForProtocol()` es un stub (devuelve 0), por lo que hoy el bloqueo de cambio de `technique_id` nunca se activa. No se cambia aquí (activarlo bloquearía ediciones hoy permitidas); se agrega un conteo nuevo y separado. Requiere decisión de negocio aparte.
- R11 Rendimiento de `GET /alerts`: calcula `plan()` (proyección + una consulta) para `out_of_sync` y `meta.warnings`. Con programas grandes, medir; plan B: cachear 60 s por programa en `Cache`.
- R12 El módulo `programs` del front sigue sin i18n (DEC-14). Riesgo de deuda, no funcional.
- R13 Desvíos explícitos de la spec: (a) `ALERT_IN_PAST` severidad `info` en el modo Tab; (b) `context.items[]` en advertencias agregadas; (c) los destinatarios quitados quedan `suppressed` en vez de borrarse; (d) el 9 móvil de AR no se muestra en el número enmascarado; (e) pie de opt-out de 43 caracteres en lugar del sugerido de 65; (f) cuerpo v2 con etiqueta "Indicaciones:" y línea final estática.
- R14 Multi-tenant: toda consulta nueva filtra por `vet_id` (alertas) y valida pertenencia del programa vía `findByGuidForVet`; los guids de destinatarios de `retry` se resuelven siempre dentro de alertas del programa del tenant. Revisión específica en QA: probar con dos vets que comparten `subject_id` numérico distinto de programa (morph por id interno).
- R15 Multi-país: ningún copy ni validación asume Argentina; el idioma de plantillas sigue global ("es"); Brasil requiere pt-BR antes de abrirse (DU-10, fuera de alcance).
- R16 Producción sin SSH: si el deploy no ejecuta migraciones y alguien despliega el código antes del SQL, las consultas por `logical_key`/`failure_code` fallan (500). Mitigación: orden estricto SQL -> código y verificación de columnas en el checklist de despliegue; opcionalmente un `health check` que consulte `information_schema` al arrancar (no incluido).
- R17 El working tree tiene cambios sin commitear en archivos que PR-14/PR-15/PR-13 tocan; coordinar el orden de merge.

## Rollback

- Código (cualquier PR): revertir el deploy. El esquema es aditivo y nullable: el código anterior ignora las columnas nuevas. Alertas `cancelled` y sin `logical_key` conviven con el listener viejo (filtra `pending`; ignora `cancelled`).
- PR-1/PR-2: sin riesgo de datos; revertir restaura el dispatcher anterior (al volver al fan-out previo, las alertas ya reclamadas simplemente están `dispatched`).
- PR-5: apagar con `ALERTS_RECONCILER_ENABLED=false` solo afecta a los triggers automáticos (nocturno/protocolo); los de usuario (guardar) siguen reconciliando. Si hay que volver al comportamiento anterior, revertir el PR; las alertas creadas por el reconciliador son válidas para el listener viejo. Para deshacer una reconciliación errónea: la tabla de auditoría identifica las alertas canceladas (`cancel_reason`, `cancelled_at`); restaurar con `UPDATE alerts SET status='pending', cancelled_at=NULL, cancel_reason=NULL WHERE cancelled_at >= '<hora>'` solo para las que aún no vencieron, y volver a correr "Recalcular" para reasignar `logical_key`.
- Plantilla v2: apagar `WHATSAPP_TASK_DUE_V2_ENABLED` (o dejar de cargar el id); el builder vuelve a v1 sin tocar datos. Los mensajes ya enviados con v2 conservan `template_version=2`.
- SQL de producción: cada script incluye sección ROLLBACK comentada (DROP COLUMN/INDEX/TABLE y DELETE de la fila en `migrations`); se usa solo si ningún código nuevo está desplegado. El permiso nuevo se revierte borrando `programs.alerts.read` de `role_has_permissions`/`permissions` e invalidando la caché de Spatie.
- Frontend: la pestaña y el panel están detrás de permiso/datos del backend; revertir el deploy FE deja el flujo de guardado anterior (el backend sigue devolviendo `warnings` que el FE viejo ignora).

## Checklist de QA manual

Preparación: dos vets (tenants A y B), un cliente con 2 establecimientos, un protocolo con tareas y alertas para `vet`, `client-owner` y `client-manager`, responsables con y sin WhatsApp, con solo correo, con número inválido y con baja registrada (`OptOut`).

1. Crear programa nuevo: el panel "Revisá los avisos" aparece, muestra cliente/establecimiento/protocolo/técnica/responsables, resumen ("Se programarán N avisos para M personas entre … Los K no se enviarán"), por grupo y fecha, con número enmascarado y "Ver mensaje" con el texto exacto. Sin críticas: un clic guarda. Verificar en DB que el preview no creó `alerts`.
2. Programa con alerta para un rol sin responsable: `ALERT_ROLE_WITHOUT_RECIPIENT` crítica; "Confirmar y guardar" exige el checkbox; guardar completa; la respuesta repite la advertencia.
3. Target retroactivo: advertencia agregada `ALERT_IN_PAST` con `count` y detalle expandible; los avisos pasados no se crean; "hoy 09:00 ya pasó" se marca.
4. Responsables: sin WhatsApp con correo (`RECIPIENT_EMAIL_ONLY`, no crítico), sin WhatsApp ni correo (crítica), número inválido, con baja. Cargar un WhatsApp con "Usado para alertas" y refrescar: la advertencia desaparece sin regenerar.
5. Dos grupos con la misma fecha: `DUPLICATE_TARGET_DATE`; el mensaje v2 incluye "grupo del dd/mm/aaaa" y con un solo grupo no.
6. Pestaña "Avisos": contadores, filtros por estado/persona/grupo/tipo en servidor, paginación de 25, orden (programados asc, resto desc), punto rojo con problemas, vista móvil sin scroll horizontal, estados vacíos y error con "Reintentar". Ningún texto técnico, `failure_reason`, guid, número completo ni "confirmado".
7. Fallo de WhatsApp + correo de respaldo enviado: una sola fila "No llegó por WhatsApp — se envió por correo" con detalle de intentos. `sent` sin `delivered_at`: "Enviado — Aún sin confirmación de entrega". Webhook `delivered` -> "Entregado"; `read` -> "Leído".
8. Códigos de fallo: forzar `no_whatsapp_contact`, `invalid_number`, `opted_out` (responder BAJA), `provider_rejected`, `service_misconfigured` (quitar el id de plantilla en un entorno de prueba: debe verse el aviso interno y el copy "Ya fue informado al equipo"), `provider_unreachable`. Históricos sin código -> "No se pudo enviar (sin más detalle)".
9. Reenviar: fallo `no_whatsapp_contact` ya corregido -> "En proceso" y se envía; segundo clic inmediato -> bloqueado; con correo de respaldo ya enviado -> "Ya llegó por correo"; con baja -> nunca reenvía; sin `programs.update` el botón no aparece.
10. Edición: cambiar solo animales -> 0 cambios (mismos guids). Cambiar la fecha de un grupo de dos -> solo cambian esos avisos; el preview muestra `diff` (mantiene/reprograma de->a/cancela/agrega/ya enviados no modificados). Cambiar protocolo -> los pendientes anteriores quedan "Cancelado" con motivo y los nuevos "Programado". Agregar/quitar responsable y grupo: según la matriz de RF-11. Los avisos cancelados aparecen como "Cancelado" solo en "Todos".
11. Carrera: con un aviso vencido hace <1 min y scheduler detenido, editar el programa: el aviso se conserva y sale en el siguiente tick. Con el scheduler caído 1 h y 5 vencidas: ninguna se pierde.
12. Cancelar programa con jobs en cola/reintento: no se envía nada más (`no_longer_applicable`), se envía el aviso de cancelación, la pestaña muestra el banner y el historial.
13. Editar un protocolo en uso: confirmación "se usa en N programas activos"; luego el detalle y lo programado coinciden (ejecutar el worker). Forzar un programa desalineado (borrar una alerta a mano): aparece "Los avisos programados no coinciden…" y "Recalcular avisos" lo corrige (segunda pulsación sin cambios).
14. Plantilla v2 (con la bandera encendida): mensaje real en un teléfono del equipo con vet/empresa, cliente, establecimiento, protocolo (+grupo), tarea, "Cuándo" (relativo + fecha concreta + hora de la tarea), indicaciones, aviso automático y pie "Respondé BAJA…". Texto libre con saltos de línea, tabs, más de 200 caracteres y vacío. Alerta legacy con la bandera encendida sale con v1. Responder BAJA y verificar la supresión.
15. Multi-tenant: con el tenant B, usar guids de A en preview/alerts/retry/rebuild y en `store` (client/establishment/protocol/manager) -> 404/422; verificar que las alertas de A nunca aparecen.
16. Permisos: usuario sin `programs.alerts.read` no ve la pestaña ni obtiene datos (403); rol `client-*` sin acceso; tras correr el SQL 03, los roles `vet`, `vet-administrative`, `vet-assistant` ven la pestaña (verificar invalidación de caché).
17. Multi-país: vet de otro país (MX/CL): hora local correcta, prefijo y validación sin supuestos de AR, nombres de día en español.
18. Móvil: panel de preview y pestaña usables en pantalla angosta.

## Pendientes / fuera de alcance

- Respuestas/confirmaciones de destinatarios ("Requiere confirmación" informativo), DU-04 (aviso automático de reprogramación), DU-03 (push en la pestaña), DU-07 ("enviar ahora" para vencidas), DU-09 (aviso al editar contactos con pendientes), DU-10 (pt-BR y otros idiomas), DU-12 (indicador en el listado de programas), consolidación de mensajes diarios (R6 de la spec).
- H8: `alert_recipients.user_profile_id` con `cascadeOnDelete` (propuesta: nullable + `nullOnDelete` + `recipient_name` snapshot) y política de retención/purga de `rendered_body`.
- Barrido de destinatarios "atascados" (alerta `dispatched` con destinatarios `pending` sin job) y verificación de salud al arrancar (`information_schema`).
- Persistencia del estado "finalizado" del programa; reactivar programas cancelados.
- Activar el bloqueo real de `technique_id` en `ProtocolService` (stub `countProgramsForProtocol`).
- i18n del módulo `programs`.
