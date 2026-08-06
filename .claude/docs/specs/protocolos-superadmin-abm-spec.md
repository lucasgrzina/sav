# Spec funcional: ABM de Protocolos (SuperAdmin) — capa plantilla

## Contexto

SAV necesita que un SuperAdmin pueda administrar la "capa plantilla" de protocolos reproductivos/sanitarios (`protocols`, `protocol_tasks`, `protocol_task_alerts`) asociada a las sub-técnicas del árbol de `techniques`, para que luego un vet pueda instanciarlas como `Program` (fuera de alcance de esta spec). Hoy `techniques.protocols_name` es un string suelto sin relación real: esta feature lo reemplaza por un vínculo real y gestionable.

Esta spec cubre EXCLUSIVAMENTE la capa plantilla (crear, editar, eliminar protocolos con sus tareas y alertas) desde el panel SuperAdmin. No cubre `programs` (instancia), el motor de despacho de `alerts`/`alert_user`, ni el flujo del vet.

## Alcance

- CRUD completo de `Protocol` (crear, listar, editar, eliminar) desde el tab "Protocolos" de `TechniqueDetailPage.vue` (página de la técnica RAÍZ).
- Gestión anidada de `ProtocolTask` (tareas) dentro del formulario de protocolo: alta, edición, eliminación, reordenamiento (`sort_order`).
- Gestión anidada de `ProtocolTaskAlert` (alertas) dentro de cada tarea: alta, edición, eliminación, reordenamiento (`sort_order`), selección de roles receptores.
- Selector de sub-técnica (child de la técnica raíz) al crear/editar un protocolo.
- Selector de país (`country_id`, nullable) por protocolo.
- Permisos nuevos `protocols.*` siguiendo la convención `module.action`.

## Fuera de alcance

- `programs`, `program_tasks`, `program_task_alerts` (capa instancia).
- Motor de despacho (`alerts`, `alert_user`, `HasAlertsTrait`, `SendAlertsNotifications`, Twilio/WhatsApp/scheduler).
- Flujo de creación de programa por el vet (wizard, `suggested-recipients`, overrides).
- UI de creación de protocolos propios por un vet (`vet_id` no nulo). El esquema lo contempla (`created_by_type`, `created_by_id`) pero esta iteración es SOLO SuperAdmin: `vet_id` siempre `null`, `created_by_type` siempre `'superadmin'`.
- Duplicar/clonar protocolo (no pedido; si se necesita, es RF nuevo a futuro).
- Página de detalle separada por sub-técnica: no existe, todo se gestiona desde el tab de la técnica raíz.

## Requerimientos funcionales

### RF-01 — Listar protocolos de una técnica raíz agrupados por sub-técnica

Como SuperAdmin, quiero ver todos los protocolos de las sub-técnicas de una técnica raíz, para administrar el catálogo completo desde un solo lugar.

Criterios de aceptación:
- Given estoy en `TechniqueDetailPage.vue` de una técnica raíz con sub-técnicas, When abro el tab "Protocolos", Then veo una tabla con todos los `protocols` cuyo `technique_id` pertenece a algún child de esa raíz, agrupados o filtrables por sub-técnica.
- Given la técnica raíz no tiene sub-técnicas (`children` vacío), When abro el tab "Protocolos", Then veo un estado vacío indicando que primero debe crearse al menos una sub-técnica (bloqueo funcional, ver RF-06).
- Given hay protocolos con `country_id` distinto, When listo, Then puedo filtrar por país.
- La tabla muestra: nombre, sub-técnica, país (o "Global" si `country_id` es null), color, cantidad de tareas, fecha de creación.

### RF-02 — Crear protocolo con tareas y alertas anidadas

Como SuperAdmin, quiero crear un protocolo completo (datos generales + tareas + alertas por tarea) en una sola operación, para no dejar protocolos a medio configurar.

Criterios de aceptación:
- Given estoy en el formulario de alta, When completo `technique_id` (obligatorio, debe ser un child de la técnica raíz actual), `name` (obligatorio), `color` (opcional), `country_id` (opcional), Then puedo agregar N tareas.
- Given estoy agregando una tarea, When completo `description` (obligatorio), `days_offset` (obligatorio, entero, puede ser negativo — ver RD-01), `time_of_day` (obligatorio, `before`/`after`), `time` (obligatorio), `important` (opcional, default false), Then la tarea queda en el borrador del formulario con un `sort_order` autoasignado por posición.
- Given estoy en una tarea del borrador, When agrego una alerta, Then completo `offset_days` (obligatorio, entero, default 0), `time_of_day` (obligatorio, `before`/`after`, default `before`), `time` (obligatorio), `roles` (obligatorio, mínimo 1 rol — ver RD-02), `message` (obligatorio), `require_confirmation` (opcional, default false).
- Given completé el formulario y confirmo el alta, When envío, Then protocolo + tareas + alertas se crean en una sola transacción atómica (todo o nada, según el patrón `POST /api/v2/protocols` del doc base).
- Given el alta fue exitosa, When vuelvo al listado, Then el nuevo protocolo aparece bajo su sub-técnica.
- Given la transacción falla en cualquier punto (ej. validación de una alerta tardía), When se reintenta el submit, Then no queda ningún registro parcial (protocolo sin tareas, tarea sin alertas huérfana, etc.).

### RF-03 — Editar protocolo, tareas y alertas existentes

Como SuperAdmin, quiero editar los datos generales de un protocolo y agregar/editar/eliminar tareas y alertas, para corregir o ajustar un protocolo publicado.

Criterios de aceptación:
- Given un protocolo existente, When entro a edición, Then veo precargados todos sus datos, tareas y alertas.
- Given edito el nombre, color, país o sub-técnica del protocolo, When guardo, Then se actualiza `protocols` sin afectar tareas/alertas no tocadas.
- Given agrego una tarea nueva a un protocolo existente, When guardo, Then se crea con `sort_order` al final de la lista actual.
- Given elimino una tarea existente, When guardo, Then la tarea y sus alertas hijas se eliminan (ver RD-04 sobre soft delete vs hard delete).
- Given reordeno tareas (drag-and-drop o flechas), When guardo, Then se persisten los nuevos `sort_order` de todas las tareas afectadas.
- Given reordeno alertas dentro de una tarea, When guardo, Then se persisten los nuevos `sort_order` de esas alertas.
- Given cambio la sub-técnica (`technique_id`) de un protocolo que ya tiene tareas, When guardo, Then el cambio se permite siempre que la nueva sub-técnica sea child de la misma técnica raíz (ver DU-01, riesgo de romper agrupación visual si se permite mover entre raíces distintas).

### RF-04 — Eliminar protocolo

Como SuperAdmin, quiero eliminar un protocolo que ya no se usa, para mantener el catálogo limpio.

Criterios de aceptación:
- Given un protocolo sin `programs` asociados (instancias creadas a partir de él), When lo elimino, Then se hace soft delete de `protocols` (columna `deleted_at` ya definida en el doc base) y de sus `protocol_tasks`/`protocol_task_alerts` en cascada lógica.
- Given un protocolo CON `programs` asociados, When intento eliminarlo, Then el sistema bloquea el borrado y muestra un mensaje explicando que tiene programas activos vinculados (ver RD-03 — regla dura de integridad, aunque `programs` esté fuera de alcance de implementación acá, la validación de existencia debe contemplarse porque rompe si se ignora).
- Given elimino una tarea individual dentro de un protocolo que NO tiene programas asociados, When confirmo, Then se eliminan también sus alertas hijas.

### RF-05 — Seleccionar roles receptores por alerta

Como SuperAdmin, quiero elegir uno o más roles tenant como receptores sugeridos de cada alerta, para que la plantilla ya proponga a quién notificar cuando el vet instancie un programa.

Criterios de aceptación:
- Given estoy configurando una alerta, When abro el selector de roles, Then las opciones son exactamente: `vet`, `vet-assistant`, `vet-administrative`, `client-owner`, `client-manager`, `client-administrative` (roles tenant seedeados, `RoleSeeder.php`).
- Given no selecciono ningún rol, When intento guardar la alerta, Then el sistema rechaza el guardado (`roles` no puede ser array vacío — ver RD-02).
- Given selecciono más de un rol, When guardo, Then `protocol_task_alerts.roles` persiste como JSON array con todos los roles elegidos.

### RF-06 — Restricción: protocolo siempre sobre sub-técnica, nunca sobre técnica raíz

Como sistema, quiero impedir que un protocolo se asocie a una técnica raíz, para respetar la regla de dominio de que la raíz no tiene protocolos propios.

Criterios de aceptación:
- Given el selector de sub-técnica en el formulario de alta/edición, When se puebla, Then solo lista `techniques` cuyo `parent_id` sea la técnica raíz actual (nunca la raíz misma, nunca sub-técnicas de otras raíces).
- Given una técnica raíz sin children, When se intenta crear un protocolo desde su tab, Then el formulario está deshabilitado con mensaje explicando que se debe crear una sub-técnica primero.

## Requerimientos no funcionales

- **Performance**: el listado de protocolos por técnica raíz debe traer tareas/alertas solo bajo demanda (lazy, `with=tasks.alerts` únicamente al abrir edición), no en el listado general, para evitar N+1 sobre árboles con muchas sub-técnicas y protocolos.
- **Seguridad / multi-tenant**: `protocols` creados por SuperAdmin son globales (`vet_id = null`), visibles a todos los tenants — el scope de tenant NO aplica a la escritura en esta spec (es panel SuperAdmin, no contexto de vet autenticado), pero si en el futuro se reutiliza el mismo endpoint para vets, la creación con `vet_id` no nulo SÍ debe respetar el scope de tenant del vet autenticado (fuera de alcance acá, pero el endpoint debe diseñarse para no romper esa extensión).
- **Auditoría**: cada `protocols` persiste `created_by_type='superadmin'` y `created_by_id` (id del usuario SuperAdmin autenticado). No se pide historial de cambios (no hay RF de auditoría de ediciones) — si se necesita, es una duda abierta (ver DU-03).
- **Multi-país**: `protocols.country_id` es nullable. `null` significa protocolo global (visible en todos los países), valor seteado significa protocolo restringido a un país específico. El filtro de listado y el selector de país en el formulario deben usar el catálogo real de `countries` (no hardcodear Argentina).

## Impacto en dominio SAV

- **Protocolos / tareas**: implementa por primera vez `Protocol` y `ProtocolTask` en el nuevo backend (hoy no existen). Cumple la regla dura #1 (`days_offset` + `time_of_day` obligatorios en toda tarea) — el formulario de tarea no permite guardar sin ambos campos.
- **Alertas y notificaciones**: implementa `ProtocolTaskAlert` con `roles` explícito (regla dura #2) — el selector de roles no permite guardar sin al menos un rol. `require_confirmation` se modela como bool en la alerta (regla dura #7 aplica a nivel de instancia/`Program`, fuera de esta spec, pero el campo se define acá en la plantilla para que la instancia lo herede).
- **Planes sanitarios**: sin impacto — `HealthPlan` no se toca en esta spec.
- **Animales / Establecimientos**: sin impacto directo. `protocols` no referencia animales; la vinculación a `Establishment`/`Animal` ocurre en `Program` (fuera de alcance).
- **Roles y permisos**: se agregan `protocols.create`, `protocols.read`, `protocols.update`, `protocols.delete` (convención `module.action` verificada en `PermissionSeeder.php`/`RoleSeeder.php`). Estos permisos se asignan al rol `super-admin` (que ya sincroniza `Permission::all()`), no a roles tenant en esta iteración (los roles tenant son solo receptores de alertas, no administradores de protocolos).
- **Multi-tenant**: `protocols` de SuperAdmin son globales (`vet_id = null`); no hay scope de tenant en lectura/escritura para este panel.
- **Multi-país**: `country_id` agregado como campo nuevo respecto al doc base v2 (decisión ya tomada con el usuario). Nullable, FK a `countries`, filtrable en el listado.

## Riesgos y alertas

- **RD-01 — `days_offset` negativo en `protocol_tasks`**: el doc base no aclara si `days_offset` admite negativos (tareas previas al D0, ej. "revisión pre-protocolo"). Esta spec asume que SÍ puede ser negativo porque el dominio veterinario admite tareas antes del D0 (ej. diagnóstico previo), pero es una ambigüedad que debe confirmarse con `arquitecto`/negocio antes de fijar la validación del Form Request.
- **RD-02 — `roles` vacío en alerta**: regla dura #2 exige roles explícitos; esta spec fuerza mínimo 1 rol por alerta a nivel de validación de formulario y backend. Marcado como bloqueante, ya resuelto en RF-05.
- **RD-03 — Borrado de protocolo con `programs` asociados**: el doc base no define esta regla de integridad porque `programs` está fuera de su capa. Esta spec la incluye como requerimiento (RF-04) porque ignorarla permitiría borrar la plantilla de programas ya instanciados y romper el historial/snapshot. El arquitecto debe decidir si la validación de existencia de `programs` se hace por FK constraint o por query explícita en el controller, dado que `programs` no se implementa en este mismo trabajo.
- **RD-04 — Soft delete en cascada de `protocol_tasks`/`protocol_task_alerts`**: el doc base solo define `deleted_at` en `protocols`, no en `protocol_tasks` ni `protocol_task_alerts`. Si estas tablas no tienen soft delete propio, borrar un protocolo con hard-delete de tareas/alertas rompe la trazabilidad de `program_tasks.protocol_task_id` (FK nullable, pero perdería el registro origen). Riesgo para `arquitecto`: decidir si `protocol_tasks`/`protocol_task_alerts` también necesitan `deleted_at`.
- **Multi-país**: confirmado con el usuario que `country_id` es requerido en el esquema (no estaba en el doc base). Si se implementa sin este campo, es una violación de la regla dura #5 (multi-país desde el diseño).

## Decisiones sobre las dudas abiertas (resueltas con el usuario, 2026-07-20)

- **DU-01 — RESUELTO: `technique_id` inmutable si el protocolo tiene `programs` instanciados.** Un protocolo sin programas asociados puede cambiar de sub-técnica libremente (dentro de la misma raíz, per RF-06). En cuanto existe al menos un `Program` creado a partir de ese protocolo, `technique_id` queda bloqueado para edición — el backend debe validar esto en el Form Request de update, igual que la validación de borrado de RF-04.
- **DU-02 — RESUELTO: `protocols.name` único por `technique_id` + `country_id`.** Se agrega índice único compuesto (`technique_id`, `country_id`, `name`) — nota: `country_id` nullable participa en el índice único tratando `null` como un valor propio (comportamiento estándar de índice único con NULL en MySQL/Postgres: dos filas con mismo `technique_id`+`name` y `country_id` NULL SÍ se consideran duplicado solo si la DB trata NULL como valor comparable, a validar por `arquitecto` según motor de DB usado — puede requerir constraint a nivel de aplicación en vez de índice DB puro).
- **DU-03 — RESUELTO: no se implementa auditoría de ediciones en esta iteración.** Alcanza con `created_by_type`/`created_by_id`. Sin tabla de historial ni versionado.
- **RD-01 — RESUELTO: `protocol_tasks.days_offset` admite valores negativos.** Confirmado: el dominio veterinario permite tareas previas al D0 (ej. diagnóstico/revisión pre-protocolo). La validación del Form Request no debe restringir el signo del entero.

## Pendiente para `arquitecto` (no son dudas de negocio, son decisiones técnicas)

- RD-03: mecanismo de validación de "protocolo con `programs` asociados" (FK constraint vs. query explícita en controller), dado que `programs` no se implementa en este mismo trabajo.
- RD-04: si `protocol_tasks`/`protocol_task_alerts` necesitan `deleted_at` propio (soft delete) para no romper trazabilidad de `program_tasks.protocol_task_id` en el futuro.
- Implementación exacta del índice único de DU-02 dado el manejo de NULL en `country_id`.
