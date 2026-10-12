# Spec funcional: Fase 3 — Verificabilidad de la automatización y WhatsApp con contexto

Origen: minuta de revisión del 11/10/2026 (`docs/Analisis y correcciones V1.md`), secciones 4 ("Hacer verificable la automatización") y 5 ("WhatsApp claro y con contexto").
Estado: borrador funcional listo para `arquitecto`. Sin código. Endpoints y columnas son propuestas funcionales a confirmar en el plan técnico.

## Contexto

SAV convierte protocolos en tareas con fecha y programa avisos de WhatsApp para veterinarios, propietarios y personal de campo. Hoy el veterinario no puede comprobar que el aviso correcto quedó preparado para la persona correcta en el momento correcto: no hay pantalla ni endpoint para consultar avisos o envíos, hay descartes silenciosos al generar, los cambios sobre un programa pueden dejar avisos viejos o perder avisos válidos, y el mensaje de WhatsApp no dice veterinario, establecimiento, tarea ni fecha concreta.

Objetivo de la fase: que el usuario vea, antes y después de guardar, qué se va a enviar / se envió / falló, y que el destinatario entienda cada mensaje sin buscar conversaciones anteriores.

## Verificación contra el código (hecha antes de redactar)

Confirmado tal como se describió:
- `alerts` (status `pending|dispatched`, `scheduled_at` UTC, `payload`) y `alert_recipients` (status `pending|sent|delivered|read|failed|suppressed`, `attempts`, `failure_reason`, `sent_at`, `delivered_at`, `confirmed_at`, `provider_message_id`). No hay ruta de lectura de alertas: `routes/api/programs.php` solo expone index/store/show/update/cancel/pdf/share-recipients/share.
- `GenerateProgramTaskDueAlertsListener`: borra TODAS las `task_due` en `pending` del programa y las recrea; descarta en silencio `scheduledAt->isPast()` y alertas sin destinatarios por rol. Se dispara en create, update y en `ProgramManagerLinkService::regenerateAlerts`.
- `AlertRecipientFactory`: crea un destinatario por canal en `default_channels = [Whatsapp, Push]` (Push solo si hay suscripción). Fallback `whatsapp -> email` en `ChannelFallbackService`. El contacto se resuelve recién en `AlertRecipient::toDto()` (al enviar); sin contacto WhatsApp con `use_for_alerts` -> `RecipientContactNotFoundException` -> `Failed` -> fallback a email.
- `ProgramTaskDueMessageBuilder` WhatsApp: variables `{1: nombre destinatario, 2: nombre del protocolo, 3: mensaje libre}`.
- `ProgramTarget` solo tiene `target_date` (+ animales): NO tiene nombre. `Vet` tiene `name`. `Program` no guarda quién lo creó.
- Opt-out entrante existe (`RecordInboundOptOut`: baja/stop/cancelar/desuscribir; alta/start/suscribir) y `OptOutPolicy` suprime el envío (`Suppressed`, `opted_out`).

Correcciones de detalle al contexto recibido:
- `ProgramService::projectTargetTasks` es privada y está en líneas ~221-256 (no 190-235). Se invoca solo desde `findByGuidForVet`.
- La proyección difiere del listener: no descarta fechas pasadas, no omite alertas sin destinatarios, no resuelve contacto ni opt-out y devuelve hora local sin zona. Hoy el detalle muestra como "programados" avisos que el listener nunca crea. Este es el origen probable de falsa confianza.

Hallazgos nuevos (leídos en el código; conviene reproducirlos con test antes de implementar):
- H1 (alto) Pérdida de avisos vencidos por edición. Un `Alert` queda `pending` entre su `scheduled_at` y el siguiente tick del dispatcher (hasta ~1 min, o indefinido si el scheduler está caído). Si se edita el programa en esa ventana, el listener lo borra (está `pending`) y NO lo recrea (`isPast`). El aviso se pierde sin rastro.
- H2 (medio) Carrera dispatcher/edición: si el listener borra una alerta que el dispatcher ya encoló, `DeliverAlertJob` hace `findOrFail` y reintenta 5 veces con error (ruido, sin envío).
- H3 (medio) Avisos ya despachados siguen vivos: los jobs en reintento (backoff hasta ~30 min, 5 intentos) y los fallbacks a email no revalidan que el programa siga activo ni que el destinatario siga siendo responsable. `ProgramTaskDueMessageBuilder` no devuelve `null` (a diferencia de `HealthPlanMonthMessageBuilder`). Cancelar un programa no frena un aviso ya despachado.
- H4 (medio) Editar la definición de un protocolo (`ProtocolService::update`, solo bloquea cambiar `technique_id`) no regenera los avisos pendientes de los programas que lo usan, mientras la proyección sí lee el protocolo vivo: el detalle y lo realmente programado divergen.
- H5 (bajo/medio) No se guarda a qué número se envió ni qué texto salió; `failure_reason` es texto crudo (excepción, mensaje de Twilio/Meta, incluye guid de perfil): no es presentable.
- H6 (bajo) Dos grupos con la misma fecha generan avisos idénticos para la misma persona, indistinguibles por no incluir el grupo.
- H7 (bajo) La zona horaria sale del país del vet (`Vet::timezone()`), no del establecimiento.
- H8 (informativo) `alert_recipients` tiene cascade al borrar el perfil: se pierde el historial de avisos si se elimina la persona.

## Alcance

- F3.1 Pestaña "Avisos" en el detalle del programa (historial y visibilidad).
- F3.2 Advertencias al guardar (contrato de `warnings`).
- F3.3 Resumen previo a guardar (preview sin persistir).
- F3.4 Reprogramación confiable (reconciliación, carrera, duplicados, cambio de contacto).
- F3.5 Mensaje de WhatsApp con contexto (contenido, texto libre, plantillas, límites).

## Fuera de alcance

- Respuestas del destinatario y confirmaciones ("Requiere confirmación" sigue siendo SOLO informativo hasta V2). No se muestra `confirmed_at` ni estado "confirmado" en ninguna pantalla.
- Estado "finalizado" de programa (solo `cancelled_at`).
- Reactivar programas cancelados; estado borrador.
- Aviso automático de "reprogramación" al destinatario (candidato V2; ver DU-04).
- Notificaciones push/internas en la pestaña (ver DU-03).
- Registro de tareas realizadas; agenda; planes sanitarios (`health_plan.month`).
- Indicador de problemas en el listado de programas (seguimiento posterior).
- Reglas de lenguaje (minuta sección 6) salvo el copy nuevo de esta spec.

## Reglas transversales (aplican a todos los RF)

- RT-01 Una sola regla de cálculo: proyección (detalle), preview, advertencias y generación real deben usar la misma lógica (fecha de tarea = target_date ± days_offset/time_of_day; fecha de aviso = fecha de tarea ± offset_days/time_of_day de la alerta; hora configurada en zona del vet; destinatarios por `ProtocolTaskAlert.roles`). Lo previsualizado debe ser lo que se persiste.
- RT-02 Las horas se muestran siempre en la zona horaria del vet (país), con día de semana; se almacenan en UTC.
- RT-03 Números de teléfono siempre enmascarados en pantalla y API (solo prefijo de país y últimos 4 dígitos). Nunca el `id` interno: todo por `guid`.
- RT-04 "Enviado", "entregado" y "leído" son hechos distintos y solo se muestran si hay dato que los respalde (`sent_at`, `delivered_at`, status `read` por webhook). Ausencia de lectura no significa "no leído" (privacidad de WhatsApp).
- RT-05 Ningún texto técnico (excepciones, guids, mensajes crudos del proveedor) llega a pantallas del tenant.

---

## Requerimientos funcionales

### F3.1 — Historial y visibilidad de avisos por programa (prioridad P0)

RF-01 — Pestaña "Avisos" en el detalle del programa
  Como vet / vet-assistant / vet-administrative, quiero ver los avisos de un programa agrupados por estado, para comprobar qué quedó programado, qué se envió y qué tuvo un problema.
  El detalle pasa a tener pestañas: "Cronograma" (hoy card "Grupos") y "Avisos". La pestaña muestra un punto rojo si hay avisos con problema.
  Criterios de aceptación:
    - Given un programa con avisos, When abro "Avisos", Then veo contadores "Programados N · Enviados N · Con problema N" y una lista paginada (25 por página) ordenada: programados por fecha ascendente, enviados/con problema por fecha descendente.
    - Given filtros Estado (Todos / Programados / Enviados / Con problema), Persona, Grupo, Tipo de aviso, When filtro, Then la lista y los contadores respetan el filtro (filtrado en servidor).
    - Given un programa de otro vet (tenant), When intento ver sus avisos, Then recibo 404 (mismo criterio que `findByGuidForVet`).
    - Given pantalla angosta, When abro la pestaña, Then cada aviso se muestra como tarjeta con persona, fecha-hora, estado y motivo sin scroll horizontal.

RF-02 — Contenido de cada fila de aviso
  Columnas: fecha-hora de envío (zona del vet, "jue 24/09/2026 09:00"), destinatario, función (rol), canal ("WhatsApp" / "Correo (respaldo)"), tarea y texto del aviso, grupo (solo si el programa tiene más de un grupo), estado, motivo (si aplica), número enmascarado.
  Tipos de aviso listados: Recordatorio de tarea (`program.task_due`), Aviso de creación (`program.created`), Cancelación (`program.cancelled`), PDF compartido (`program.pdf_shared`). Push queda fuera (DU-03).
  Criterios:
    - Given un aviso con envío por WhatsApp fallido y correo de respaldo enviado, When veo la fila, Then se muestra una sola línea por (aviso x persona) con el estado "No llegó por WhatsApp — se envió por correo" y el detalle expandible con los dos intentos.
    - Given un aviso `sent` sin `delivered_at`, When lo veo, Then dice "Enviado" con la leyenda "Aún sin confirmación de entrega", no "Entregado".
    - Given un aviso con status `read`, When lo veo, Then dice "Leído" con la hora de entrega; given status `delivered`, Then "Entregado" sin mencionar lectura.
    - Given cualquier aviso, Then no aparece ningún dato de confirmación (`confirmed_at`).

RF-03 — Estados mostrados (derivados; el estado de `alerts` solo no alcanza)
  | Estado visible | Condición |
  |---|---|
  | Programado | alerta `pending` con `scheduled_at` futuro |
  | En proceso | alerta vencida `pending`, o destinatario `pending` en alerta `dispatched` (cola/reintentos) |
  | Enviado | destinatario `sent` |
  | Entregado | `delivered` |
  | Leído | `read` |
  | Con problema | `failed` (con motivo) |
  | No enviado | `suppressed` (baja, ya no aplica, duplicado, límite) |
  | Cancelado | alerta cancelada por cambio/cancelación (solo si se adopta DU-02) |
  Agrupación de las pestañas internas: Programados = Programado + En proceso; Enviados = Enviado/Entregado/Leído; Con problema = Con problema + No enviado.

RF-04 — Motivo de fallo legible
  El backend expone un código estable (`failure_code`) y el frontend mapea el copy. Códigos mínimos y copy:
  | Código | Copy (es) |
  |---|---|
  | `no_whatsapp_contact` | "No tiene un WhatsApp cargado para recibir avisos. Cargalo en Personal del cliente > Contactos y activá 'Usado para alertas'." |
  | `invalid_number` | "El número no tiene WhatsApp o no es válido. Revisalo en sus contactos." |
  | `opted_out` | "La persona pidió no recibir avisos por WhatsApp (respondió BAJA)." |
  | `provider_unreachable` | "WhatsApp no respondió después de varios intentos." |
  | `provider_rejected` | "WhatsApp rechazó el mensaje." |
  | `service_misconfigured` | "Hubo un problema en el servicio de envío de SAV. Ya fue informado al equipo." (dispara alerta operativa interna) |
  | `no_longer_applicable` | "No se envió porque el programa cambió o se canceló." |
  | `unknown` | "No se pudo enviar (sin más detalle)." |
  Criterios:
    - Given un destinatario fallido por código, When veo la fila, Then veo el copy correspondiente y, si hay acción posible, un enlace ("Ir a contactos") o el botón "Reenviar" (RF-15).
    - Given registros históricos sin `failure_code`, Then se muestran con `unknown`.
    - Given cualquier fallo, Then la API del tenant nunca devuelve `failure_reason` crudo (queda solo para soporte/superadmin).
  La tabla de mapeo de códigos reales de Twilio/Kapso a estos códigos la define el arquitecto.

RF-05 — Estados vacíos y de error (copy)
  - Sin avisos: "Este programa todavía no tiene avisos programados." + "Los avisos salen de las alertas del protocolo y de los responsables que elegiste. Revisá que el protocolo tenga alertas y que haya responsables seleccionados." + botón "Editar programa".
  - Filtro sin resultados: "No hay avisos con este filtro."
  - Sin problemas (pestaña Con problema): "No hay avisos con problemas."
  - Programa cancelado (banner): "Programa cancelado el 12/10/2026. Los avisos pendientes se cancelaron; abajo queda el historial."
  - Error de carga: "No pudimos cargar los avisos." + "Reintentar".
  - Banner de advertencias vigentes (ver RF-07): "N avisos de este programa no se van a enviar. Ver detalle."

RF-06 — Permisos y multi-tenant
  - Lectura requiere permiso nuevo `programs.alerts.read` (mismo patrón que `programs.managers.read`: migración que lo otorga a quien hoy tiene `programs.read`: vet, vet-administrative, vet-assistant, super-admin).
  - Toda consulta filtra por `vet_id` del tenant (`alerts.vet_id` y programa del vet). Un guid ajeno devuelve 404.
  - Roles de cliente (`client-*`) no acceden (el panel tenant no los incluye).
  - Multi-país: zona horaria y formato de fecha del país del vet; nombres de día en español (ver DU-10 para otros idiomas).

### F3.2 — Advertencias al guardar o activar (prioridad P0)

"Activar" = guardar: no existe estado borrador; al crear/editar se generan los avisos.

RF-07 — Contrato de advertencias
  Las advertencias NO bloquean el guardado en el servidor. La UI exige confirmación explícita solo cuando hay advertencias `critical`. Se entregan en tres momentos: (a) respuesta del preview (F3.3), (b) respuesta de store/update (`warnings`, calculadas sobre lo realmente persistido y por tanto autoritativas), (c) endpoint de avisos de F3.1 (`meta.warnings`, para que sigan visibles tras guardar).
  Forma:
  ```
  warnings: [{
    code: string,
    severity: "critical" | "warning" | "info",
    context: {
      target_guid | target_ref, protocol_task_guid, protocol_task_alert_guid,
      profile_guid, name, role, roles[], scheduled_at_local, count
    }
  }]
  ```
  `context` solo usa guids y datos presentables. El copy vive en el frontend por `code`.
  Códigos:
  | Código | Severidad | Cuándo |
  |---|---|---|
  | `ALERT_ROLE_WITHOUT_RECIPIENT` | critical | Una alerta apunta a roles y ningún responsable elegido tiene ese rol (hoy se descarta en silencio) |
  | `RECIPIENT_WITHOUT_WHATSAPP` | critical | Responsable sin contacto WhatsApp con `use_for_alerts`; `context.email_fallback` indica si recibirá correo |
  | `RECIPIENT_INVALID_NUMBER` | critical | Número no plausible tras normalizar (validación por país del prefijo, sin hardcodear Argentina) |
  | `RECIPIENT_OPTED_OUT` | critical | El número tiene baja registrada (`OptOut`) |
  | `ALERT_IN_PAST` | critical | `scheduled_at` ya pasó: ese aviso NO se enviará |
  | `NO_MANAGERS_SELECTED` | critical | Programa sin responsables |
  | `RECIPIENT_EMAIL_ONLY` | warning | Sin WhatsApp pero con correo habilitado: recibirá por correo |
  | `DUPLICATE_TARGET_DATE` | warning | Dos grupos con la misma fecha objetivo (avisos indistinguibles; H6) |
  | `HIGH_VOLUME_FOR_RECIPIENT` | warning | Una persona recibiría más de 3 avisos el mismo día (umbral configurable; ver minuta sección 5 sobre saturación) |
  | `TASK_WITHOUT_ALERTS` | info | Tarea sin avisos: "Esta tarea no tiene avisos programados" |
  Criterios:
    - Given un protocolo con una alerta para `client-manager` y un programa sin encargado, When guardo, Then la respuesta incluye `ALERT_ROLE_WITHOUT_RECIPIENT` con `roles: ["client-manager"]` y el guardado se completa.
    - Given una alerta calculada para ayer, When guardo, Then `ALERT_IN_PAST` indica fecha-hora local y grupo, y ese aviso no se crea.
    - Given más de 5 advertencias del mismo código en un grupo (p. ej. target retroactivo), Then se agregan en una sola con `count` y detalle expandible.
    - Given preview OK pero el guardado ocurre minutos después y un aviso pasó a pasado, Then la respuesta de guardado incluye la advertencia nueva (autoritativa).
    - Given una persona sin WhatsApp que recibe el aviso por correo de respaldo, Then se muestra `RECIPIENT_EMAIL_ONLY` (no critical).
  Decisión de bloqueo: ver DU-01 (recomendado: no bloquear, confirmar explícitamente).

RF-08 — Aviso de "hora pasada del mismo día"
  Given una alerta de hoy a las 09:00 y ahora son las 14:00, Then se advierte como `ALERT_IN_PAST` con copy "Hoy a las 09:00 (ya pasó)". Una alerta a menos de 1 minuto futuro se considera válida (el dispatcher corre cada minuto).

### F3.3 — Resumen previo a guardar (prioridad P0)

RF-09 — Resumen "Revisar avisos" antes de guardar
  Como vet, quiero ver, antes de confirmar, quién recibe qué, en qué número, cuándo, para tener certeza.
  Flujo: al tocar "Guardar" en crear/editar programa se abre un panel "Revisá los avisos" con [Volver a editar] [Confirmar y guardar]. Con advertencias `critical` el botón exige marcar "Entiendo que estos avisos no se van a enviar".
  Contenido:
    - Encabezado: cliente, establecimiento, protocolo, técnica, responsables (nombre + función).
    - Resumen: "Se programarán 24 avisos para 3 personas entre el 21/09 y el 30/10. 2 no se enviarán."
    - Por grupo (fecha objetivo) y por fecha de envío, cada aviso en una oración: "María Pérez (Encargado) recibirá por WhatsApp (+54 9 ••• •••• 4821) el jueves 24/09/2026 a las 09:00: 'Colecta de embriones — jueves 24/09/2026 09:00'", con botón "Ver mensaje" que muestra el texto exacto de WhatsApp (ver F3.5).
    - Estado previsto por destinatario: "Se enviará", "No se enviará — motivo", "Se enviará por correo".
    - Advertencias de F3.2 ordenadas por severidad.
    - Con pocas incidencias el panel es compacto y colapsable (la minuta pide cuidar el esfuerzo de configuración).
  Criterios:
    - Given un programa nuevo válido sin advertencias, When toco Guardar, Then veo el resumen y un solo clic confirma y guarda.
    - Given un resultado de más de 50 avisos, Then se muestra agrupado y colapsado por grupo, paginado/virtualizado en la UI.
    - Given el número, Then siempre enmascarado.
    - Given falla el cálculo del resumen, Then se muestra "No pudimos generar el resumen" con [Reintentar] y [Guardar sin resumen] (el servidor no depende del preview para guardar).
    - Given el preview, Then no crea ni modifica `Alert`/`AlertRecipient`, no dispara notificaciones y no resuelve ni expone datos de otro tenant.

RF-10 — Preview calculado en backend (decisión)
  Sí: endpoint de preview sin persistir que reutiliza la lógica de proyección unificada (RT-01), no una segunda implementación en el frontend. El frontend no recalcula fechas (regla 8: la API es la fuente del contrato).
  Propuesta funcional:
    - `POST /v1/vets/{vet}/programs/preview` (alta; permiso `programs.create`) y `POST /v1/vets/{vet}/programs/{guid}/preview` (edición; permiso `programs.update`). Mismo body que store/update (sin animales). Targets nuevos se identifican con `target_ref` (índice del cliente).
    - Respuesta: `summary` (totales, ventana, zona horaria), `program` (cliente, establecimiento, protocolo, nombre de empresa), `items[]` (grupo, tarea, alerta, fecha-hora local y UTC, día de semana, `will_send`, `skip_reason`, destinatarios con `channel`, `destination_masked`, `contact_status`, `will_send`, `skip_reason`, y `rendered_message`), `warnings[]` (RF-07) y, solo en edición, `diff` (RF-14).
  Incluye resolución de contacto y opt-out en modo lectura (el envío real sigue resolviendo al enviar). `rendered_message` se arma con el mismo catálogo y las mismas variables que el builder real, para que el texto mostrado sea el que sale.
  Performance: p95 < 1,5 s para 10 grupos x 8 tareas x 3 alertas x 4 personas (~960 combinaciones); si se supera un tope (propuesta 1.000 ítems) se devuelve `truncated: true` con resumen completo.
  Multi-tenant: client_id, establishment_id, protocol_id y managers se validan contra el vet autenticado (mismas reglas que store).

### F3.4 — Reprogramación confiable (prioridad P0)

Principio: reconciliación idempotente en lugar de "borrar todo y recrear". Cada aviso programado tiene una clave lógica (programa + grupo + alerta del protocolo + `scheduled_at` + persona). Al cambiar algo se compara lo deseado con lo existente: se conserva lo igual, se cancela lo obsoleto y se crea lo nuevo. Requiere que el payload (o una columna) guarde el `guid` del grupo (hoy solo guarda `protocolTaskAlertGuid` y `message`).

RF-11 — Matriz de cambios
  | Cambio | Avisos pendientes | Avisos ya despachados/enviados | Mensaje al vet |
  |---|---|---|---|
  | Fecha objetivo de un grupo | Se recalculan solo los de ese grupo (clave nueva); los demás se conservan | No se tocan | Resumen: "N avisos ya se enviaron con la fecha anterior (23/09). Esas personas ya los recibieron; avisales si hace falta." |
  | Protocolo del programa (`protocol_id`) | Se cancelan todos los del protocolo anterior y se crean los nuevos | No se tocan | Mismo tipo de leyenda |
  | Definición del protocolo editada (H4) | Se reconcilian los programas activos que lo usan, en segundo plano; fallo por programa aislado y registrado | No se tocan | Al editar el protocolo: "Se usa en N programas activos; sus avisos pendientes se van a actualizar" (confirmación) |
  | Responsable agregado | Recibe los pendientes futuros | No recibe los pasados | Informativo |
  | Responsable quitado | Se quitan sus destinatarios pendientes | No se tocan | Informativo |
  | Teléfono / contacto de un responsable | Sin regeneración: se usa el número vigente al enviar (ver RF-13) | Falla ya registrada se puede reenviar | Ver RF-13 |
  | Grupo agregado / quitado | Se crean / se cancelan los de ese grupo | No se tocan | Informativo |
  | Animales de un grupo (alta/baja) | Sin cambios (el mensaje no depende de animales) | — | — |
  | Cancelar programa | Se cancelan todos; se envía el aviso de cancelación | Los jobs en cola o reintento NO envían (RF-12) | "Se cancelaron N avisos" |
  Criterios:
    - Given un programa con 10 avisos pendientes y edito solo los animales de un grupo, When guardo, Then los 10 conservan su guid y no se crea ni borra ninguno.
    - Given edito la fecha de un grupo de dos, Then solo cambian los avisos de ese grupo.
    - Given se cancela un aviso por cambio, Then queda registrado el motivo (DU-02) y deja de aparecer como programado.

RF-12 — Carrera con el dispatcher y avisos en vuelo
  Invariantes:
    - I1 Un aviso vencido que sigue siendo válido nunca se pierde por una edición (corrige H1): la reconciliación no borra ni excluye alertas `pending` con `scheduled_at <= now` si su clave sigue vigente; si ya no es válida se cancela con motivo, no se descarta en silencio.
    - I2 Una alerta pasa a despachada exactamente una vez y la edición solo modifica alertas aún `pending`. Si el dispatcher la tomó primero, la edición no la modifica ni la borra (exclusión mutua por alerta; el mecanismo lo define el arquitecto).
    - I3 Un `DeliverAlertJob` cuyo destinatario ya no existe termina sin error ni reintentos (corrige H2).
    - I4 Revalidación al enviar (corrige H3): antes de enviar un `task_due` se verifica que el programa siga activo, que el destinatario siga siendo responsable y que el grupo y la fecha de la clave sigan vigentes; si no, el destinatario queda `suppressed` con `no_longer_applicable` (mismo patrón que `HealthPlanMonthMessageBuilder`) y no se dispara fallback.
  Criterios:
    - Given una alerta vencida hace 20 s y aún `pending`, When edito el programa sin cambiar su fecha, Then la alerta se conserva y sale en el siguiente tick.
    - Given el scheduler caído 1 hora con 5 alertas vencidas, When edito el programa, Then ninguna se pierde.
    - Given un job en reintento y el programa se cancela, When corre el siguiente intento, Then no se envía y queda `no_longer_applicable`.

RF-13 — Cambio de teléfono o de contactos (cierre del hueco)
  Contrato: los avisos usan el contacto vigente al momento de enviar (se mantiene, no se guarda un teléfono en la alerta). Para cerrar el hueco:
    - La pestaña Avisos y el preview calculan el estado de contacto en vivo (número actual enmascarado, "sin WhatsApp", "baja").
    - Si el contacto cambia, la advertencia `RECIPIENT_*` aparece/desaparece sin regenerar nada.
    - Al registrar un envío se guarda el destino enmascarado (`destination_hint`, ej. "+54 9 ••• •••• 4821") y, recomendado, el texto enviado (`rendered_body`) para que el historial muestre a qué número y qué texto salió (H5).
    - Los fallos por falta de contacto o número inválido pueden reenviarse manualmente una vez corregido (RF-15).
    - Opcional P2: al editar/quitar un contacto de alertas de una persona con avisos pendientes, mostrar "Esta persona tiene N avisos pendientes en M programas activos" (ver DU-09).
  Criterios:
    - Given una persona sin WhatsApp y avisos pendientes, When le cargo un WhatsApp con 'Usado para alertas' antes del envío, Then el aviso sale al número nuevo y la advertencia desaparece.
    - Given un aviso ya fallido por falta de contacto, When cargo el contacto, Then el fallo permanece en el historial y ofrece "Reenviar".

RF-14 — Diff en el preview de edición
  En edición, el preview incluye `diff`: se mantienen N / se reprograman N (de -> a) / se cancelan N / se agregan N / ya enviados que no se modifican N (con fechas anteriores). La UI muestra esos conteos antes de confirmar.

RF-15 — Reenvío manual de un aviso fallido
  Acción "Reenviar" en la fila (permiso `programs.update`) solo si: el destinatario está `failed` o `suppressed` por causa corregible, el programa está activo, no existe hermano (misma alerta y persona) en `sent/delivered/read`, y no hubo reenvío en los últimos 10 minutos. Crea un nuevo intento con nueva clave de idempotencia. Nunca reenvía a quien se dio de baja.
  Criterios:
    - Given un destinatario fallido por `no_whatsapp_contact` ya corregido, When toco Reenviar, Then se encola un intento y la fila pasa a "En proceso".
    - Given el mismo aviso ya entregado por correo de respaldo, Then el botón aparece deshabilitado con "Ya llegó por correo".

RF-16 — Prevención de duplicados
  - Un destinatario no recibe dos avisos con la misma clave lógica (programa, grupo, alerta, fecha-hora).
  - Reprogramar nunca reenvía lo ya enviado.
  - El aviso de creación se envía solo al crear (no en ediciones; se mantiene).
  - El fallback a correo solo se dispara ante fallo definitivo; un `delivered/read` por WhatsApp nunca genera correo (se mantiene la protección existente en `RecordDeliveryStatus`).
  - Reintentos manuales: ver RF-15.
  - Dos grupos con la misma fecha: advertencia `DUPLICATE_TARGET_DATE` y el mensaje incluye el grupo.

RF-17 — Botón "Recalcular avisos"
  Si la reconciliación detecta que lo programado no coincide con lo que debería existir (por ejemplo, fallo parcial, cambio de protocolo no propagado), la pestaña muestra "Los avisos programados no coinciden con el plan actual" y la acción "Recalcular avisos" (permiso `programs.update`, idempotente, misma reconciliación). Es la red de seguridad de H4 y de rollbacks fallidos.

### F3.5 — WhatsApp con contexto (prioridad P1, depende de F3.1 para verificarlo)

RF-18 — Contenido mínimo del mensaje de recordatorio
  Debe identificar: veterinario o empresa (`Vet.name`), cliente y establecimiento, protocolo y grupo (si el programa tiene más de un grupo), tarea, fecha y hora concretas con día de semana, y el texto libre de la alerta.
  Propuesta de plantilla v2 (`program.task_due`), variables posicionales:
  ```
  Hola {{1}}, te escribe {{2}} por el programa "{{3}}" ({{4}}, {{5}}).
  Tarea: {{6}}
  Cuándo: {{7}}
  {{8}}
  ```
  1 nombre del destinatario; 2 nombre de la empresa del vet; 3 protocolo (+ " - grupo del 23/09/2026" solo si hay más de un grupo, para no dejar variables vacías); 4 cliente; 5 establecimiento; 6 descripción de la tarea; 7 fecha-hora de la tarea con referencia relativa; 8 texto libre saneado.
  Pie estático sugerido: "Aviso automático de SAV. Para dejar de recibirlos, respondé BAJA." (el opt-out por BAJA ya existe). Ver DU-06 sobre que el comando de Kapso solo crea BODY hoy.
  Ejemplo renderizado: "Hola María, te escribe Agropecuaria ID por el programa "Superovulación P500" (Estancia La Invernada, Cliente SA). / Tarea: Colecta de embriones / Cuándo: mañana, jueves 24/09/2026 a las 09:00 / Traer termo y camiseta de colecta."
  Criterios:
    - Given un aviso para mañana, Then "Cuándo" incluye siempre la fecha concreta aunque diga "mañana"; reglas: 0 días "hoy", 1 "mañana", 2 "pasado mañana", mayor "en N días", negativo "ayer" / "hace N días", calculado respecto del día de envío en zona del vet.
    - Given hora de la tarea, Then se muestra la hora de la TAREA (`ProtocolTask.time`), no la hora del aviso.
    - Given un programa de un solo grupo, Then no aparece mención de grupo.
    - Given una persona en varios programas, Then cada mensaje es autosuficiente (cliente, establecimiento, protocolo).
    - Given que "Requiere confirmación" es informativo, Then el mensaje NO pide responder ni confirmar.
  Canales alternativos: el correo de respaldo y push muestran el mismo contenido mínimo (las mismas variables).

RF-19 — Texto libre de la alerta
  - El texto libre se mantiene como variable `{{8}}` (último, para que un texto largo no desplace el contexto).
  - Saneado: sin saltos de línea, sin tabulaciones, sin más de 3 espacios seguidos (restricciones de variables de Meta), recorte a 200 caracteres con "...". Vacío -> "Sin indicaciones adicionales." (Meta no admite parámetros vacíos).
  - Si el texto libre contiene "hoy"/"mañana" o fechas, no se reescribe; la fecha concreta ya está en `{{7}}`.
  - El vet ve el texto saneado final en el preview (RF-09) y en el formulario de protocolo se informa el límite (contador de 200).
  - Límite de la plantilla: cuerpo de hasta 1024 caracteres y número de variables acotado (ratio texto/variables); verificar vigencia con Meta/Twilio antes de aprobar.

RF-20 — Impacto en plantillas del proveedor, versionado y compatibilidad
  - La plantilla aprobada es inmutable en la práctica: Twilio (contentSid por tipo, config por entorno) y Kapso/Meta (nombre + idioma). Cambiar el cuerpo implica una plantilla nueva y aprobación (varía de minutos a ~48 h).
  - Versionado: nueva entrada `program.task_due` v2 (nombre sugerido `sav_program_task_due_v2`) en `WhatsappTemplateCatalog` y en los comandos `TwilioCreateTemplates`/`KapsoCreateTemplates`; v1 se mantiene hasta migrar.
  - Compatibilidad con avisos ya programados: el builder decide la versión por el payload. Los avisos sin datos de contexto (generados antes del despliegue) se envían con v1; los nuevos con v2. Alternativa recomendada: tras el despliegue, un comando de reconciliación (patrón `ReconcileUnlinkedProgramManagersCommand`) regenera los pendientes con el payload nuevo, quedando v1 solo como respaldo. Requiere haber corregido H1/I1 antes.
  - Orden operativo: crear y aprobar v2 -> cargar ids/nombres en configuración -> desplegar código con v2 detrás de configuración -> regenerar pendientes -> retirar v1.
  - Mientras v2 no esté aprobada, el sistema sigue con v1 (la bandera debe estar apagada); el preview muestra el texto de la versión realmente activa.
  - Variables y límites: ver RF-19; además el builder debe validar el conteo de variables contra el catálogo (ya cubierto por `WhatsappTemplateCatalogTest`).
  - Multi-país: idioma de plantilla por país (`KAPSO_TEMPLATE_LANGUAGE` es global y vale "es"); formato de fecha dd/mm/aaaa; nombres de día en español; Brasil requeriría portugués (DU-10). Nombre del día de semana debe calcularse con locale explícito, no con el del servidor.

---

## Requerimientos no funcionales

- Performance: preview p95 < 1,5 s en el caso de ~960 combinaciones; endpoint de avisos paginado en servidor, p95 < 500 ms por página de 25.
- Seguridad / multi-tenant: todo filtrado por `vet_id`; 404 ante recursos ajenos; teléfonos siempre enmascarados; sin `id` internos; sin texto técnico del proveedor; permisos nuevos `programs.alerts.read` (lectura) y reutilización de `programs.create/update` para preview y acciones.
- Auditoría: cada reconciliación registra programa, usuario (si hay), motivo, conteos (mantenidos/creados/cancelados). Las alertas canceladas guardan `cancelled_at` y `cancel_reason`. Cada envío guarda `destination_hint`, `failure_code` y (recomendado) `rendered_body` y `template_version`. Los eventos de reintento manual quedan registrados con el usuario.
- Multi-país: zona horaria por país del vet; validación de teléfono por prefijo (sin lógica exclusiva de Argentina: se conserva el "9" móvil de AR sin recortarlo, ver `PhoneNumber`); formatos de fecha; idioma de plantillas; ver H7 y DU-08. En México o Chile no deben aparecer supuestos de Argentina en copy ni validaciones.
- Accesibilidad / móvil: pestaña y preview usables en vista angosta (minuta sección 7).

## Modelo de datos afectado (propuesto, a confirmar por arquitecto)

- `alerts`: +`cancelled_at`, +`cancel_reason` (enum: `rescheduled|protocol_changed|recipient_removed|group_removed|program_cancelled|superseded`), nuevo valor de estado `cancelled` (el dispatcher ya filtra `pending`); +`program_target_id` (o `programTargetGuid` en payload) para la clave lógica y para filtrar por grupo.
- `alert_recipients`: +`failure_code` (nullable), +`destination_hint` (nullable), +`rendered_body` (nullable, ≤ 1024), +`template_version` (nullable). `failure_reason` se conserva como detalle técnico interno.
- Índices: (`vet_id`, `subject_type`, `subject_id`, `status`, `scheduled_at`) para la pestaña; clave lógica única para evitar duplicados.
- `ProgramTaskPayload`: +`programTargetGuid`, +`protocolTaskGuid`, (opcional) snapshot de descripción y fecha de la tarea.
- Sin cambios en `Program` (solo `cancelled_at`) ni en `ProgramTarget` (grupo = "grupo del DD/MM"; DU-05).
- Permisos: +`programs.alerts.read`.
- H8: revisar `cascadeOnDelete` de `alert_recipients.user_profile_id` (riesgo de pérdida de historial).

## Endpoints a nivel funcional (propuestos; contrato por guid)

| Recurso | Método y ruta (propuesta) | Permiso | Notas |
|---|---|---|---|
| Avisos del programa | `GET /v1/vets/{vet}/programs/{guid}/alerts` (`status`, `profile`, `target`, `type`, `page`, `per_page`) | `programs.alerts.read` | Devuelve `data[]`, `meta` (contadores, `warnings`, `timezone`), estados derivados RF-03 |
| Preview alta | `POST /v1/vets/{vet}/programs/preview` | `programs.create` | Sin persistir |
| Preview edición | `POST /v1/vets/{vet}/programs/{guid}/preview` | `programs.update` | Incluye `diff` |
| Reenviar | `POST /v1/vets/{vet}/programs/{guid}/alerts/{recipientGuid}/retry` | `programs.update` | RF-15; 422 con `reason` si no corresponde |
| Recalcular | `POST /v1/vets/{vet}/programs/{guid}/alerts/rebuild` | `programs.update` | RF-17, idempotente |
| store/update existentes | respuesta con `warnings` | los actuales | RF-07 |
Errores con el patrón actual `makeError(['reason'=>...], msg, 422)`.

## Pantallas y copy (resumen)

- Detalle de programa: pestañas "Cronograma" | "Avisos" (RF-01..05).
- Formulario de programa: panel "Revisá los avisos" (RF-09), checkbox "Entiendo que estos avisos no se van a enviar".
- Confirmación de edición de protocolo en uso: "Este protocolo se usa en N programas activos. Los avisos pendientes de esos programas se van a actualizar."
- Etiquetas: "Programado", "En proceso", "Enviado", "Entregado", "Leído", "Con problema", "No enviado", "Reenviar", "Recalcular avisos", "Recibe avisos por WhatsApp", "Usado para alertas" (consistente con la minuta).

## Impacto en dominio SAV

- Protocolos / tareas: `days_offset` + `time_of_day` y `offset_days` de las alertas son la base del cálculo (RT-01); se explicita que la hora del mensaje es la de la tarea; editar un protocolo en uso pasa a propagar (H4).
- Alertas y notificaciones: roles explícitos en `ProtocolAlert.roles` (`vet`, `vet-assistant`, `client-owner`, `client-manager`) siguen definiendo destinatarios; nuevos estados, motivos, reconciliación y plantilla v2. `require_confirmation` permanece informativo (sin `confirmed_at` en pantallas).
- Planes sanitarios: sin cambios (`health_plan.month` fuera de alcance; el mismo patrón de recálculo al enviar es referencia).
- Animales / Establecimientos: el establecimiento entra al mensaje; animales no afectan avisos.
- Roles y permisos: vet, vet-administrative, vet-assistant; roles cliente sin acceso; permiso nuevo de lectura de avisos.
- Multi-tenant: filtro por `vet_id` en alertas, preview y acciones; 404 ante ajenos.
- Multi-país: zona horaria, validación de teléfono, idioma de plantillas y formato de fecha por país.

## Riesgos y alertas

- Reglas duras: regla 4 (tenant scope) exige que el listado y el preview filtren por vet; regla 6 (guid) obliga a usar guids en `warnings.context` y en las rutas de reintento; regla 5 (multi-país) prohíbe validar teléfonos solo para Argentina; regla 7 se respeta (confirmación informativa, sin nuevos campos). No se detecta violación en el pedido.
- R1 H1 (pérdida de avisos vencidos) es un defecto vigente de severidad alta independiente de la fase: conviene corregirlo primero.
- R2 Plantilla v2 depende de aprobación externa; bloquea F3.5 en producción pero no el resto.
- R3 Reconciliación + dispatcher requieren exclusión mutua correcta; se necesitan tests de concurrencia.
- R4 Alertas legadas sin `programTargetGuid` no se pueden asociar a un grupo: tratamiento por `protocolTaskAlertGuid` + fecha, o regeneración de pendientes.
- R5 Cambio de teléfono: no hay snapshot; el historial previo no tiene número. Solo hacia adelante.
- R6 Saturación de mensajes (minuta sección 5): `HIGH_VOLUME_FOR_RECIPIENT` solo advierte; la consolidación en un solo mensaje diario queda fuera.
- R7 Zona horaria del vet vs. establecimiento (H7) puede generar horas incorrectas en casos cross-país; también horas inexistentes/ambiguas en cambios de horario de verano (Chile).
- R8 Mostrar "leído" puede generar falsa expectativa por privacidad de WhatsApp; el copy lo aclara.
- R9 Exponer demasiado detalle técnico de fallos: se mitiga con `failure_code`.

## Dependencias entre ítems y orden de entrega recomendado

Dependencias: F3.2 y F3.3 comparten la proyección unificada (RT-01); F3.1 necesita `failure_code`, `destination_hint` y el estado derivado; F3.4 necesita la clave lógica en el payload y es prerrequisito de confianza para F3.3 diff; F3.5 necesita el payload extendido y que el preview muestre el texto exacto.

Orden:
0. Corregir H1/H2 (I1, I3) con tests (hotfix; no requiere pantallas).
1. Modelo de datos + proyección unificada + clave lógica (RT-01, base de F3.4).
2. F3.1 pestaña Avisos (solo lectura) + `failure_code`/`destination_hint`.
3. F3.2 advertencias + F3.3 preview/resumen (comparten backend).
4. F3.4 reconciliación completa (RF-11..17), revalidación al enviar (I4), reenvío y recalcular.
5. F3.5 plantilla v2 (en paralelo desde el paso 1: creación y aprobación con el proveedor), activación al final y regeneración de pendientes.

## Dudas abiertas para el humano (con recomendación; no frenan)

DU-01 — ¿Las advertencias bloquean? Recomendación: no bloquear en servidor; confirmación explícita en la UI ante `critical`. Un programa sin avisos válidos es legítimo (carga retroactiva).
DU-02 — ¿Cancelar avisos por cambios en soft-cancel (visibles en el historial) o borrado duro? Recomendación: soft-cancel con `cancel_reason`; da trazabilidad y responde a "no queden mensajes anteriores".
DU-03 — ¿Mostrar push/notificaciones internas en "Avisos"? Recomendación: no en esta fase; la minuta pide diferenciar WhatsApp de notificaciones internas.
DU-04 — ¿Avisar automáticamente a los destinatarios cuando se reprograma algo ya enviado? Recomendación: V2 (requiere plantilla nueva y sube el volumen); por ahora solo advertir al vet.
DU-05 — ¿Cómo identificar un grupo en el mensaje? Recomendación: "grupo del DD/MM/AAAA" solo con más de un grupo; no agregar nombre a `ProgramTarget` ahora.
DU-06 — ¿Footer de opt-out en la plantilla? Recomendación: sí; hoy `kapso:create-templates` crea solo BODY, hay que ampliarlo (y Twilio Content Composer).
DU-07 — ¿Cómo reaccionar a un aviso vencido por fecha pasada ("enviar ahora")? Recomendación: no automático; evaluar acción manual explícita más adelante.
DU-08 — ¿Zona horaria del vet o del establecimiento? Recomendación: mantener la del vet y mostrar la zona usada en preview y avisos; revisar cuando haya vets con establecimientos en otro país.
DU-09 — ¿Aviso al editar contactos de una persona con avisos pendientes (RF-13 opcional)? Recomendación: sí en P2, solo informativo.
DU-10 — ¿Idioma de mensajes para Brasil y otros países? Recomendación: español para AR/MX/CL/CO/PE; definir pt-BR antes de abrir Brasil; locale explícito en el cálculo de fechas.
DU-11 — ¿Umbral de `HIGH_VOLUME_FOR_RECIPIENT`? Recomendación: 3 avisos/día por persona, configurable.
DU-12 — ¿Mostrar el indicador "N con problema" en el listado de programas? Recomendación: sí como siguiente iteración, ya que reutiliza los contadores.
DU-13 — ¿Guardar `rendered_body`? Recomendación: sí, con retención igual al historial del programa; permite probar qué recibió cada persona.
