# TKT-004 - Simular fecha en Protocolo (Superadmin)

## Tipo
Feature acotada — va directo a `arquitecto` (no requiere `funcional`; toca un solo módulo, un flujo, sin ambigüedad de dominio).

## Contexto
Dentro del panel superadmin, en la ficha de detalle de una Técnica, la solapa de Protocolos (`TechniqueProtocolsTab.vue`) lista los protocolos de esa técnica con acciones por fila (Editar, Replicar, Eliminar). El usuario quiere una acción más, "Simular", que abra un drawer donde cargar una fecha de prueba y vea el cronograma completo de Tareas y Alertas de ese protocolo, calculado a partir de esa fecha — sin persistir nada.

Referencia visual: `docs/legacy/simular_fecha.jpg` (SOLO layout — input de fecha arriba, tabla de Tareas, tabla de Alertas debajo, ambas ordenadas cronológicamente). Es de un sistema legacy de otro dominio: columnas como "Realizada" y "Enviado" NO aplican acá y se descartan.

## Estado actual

**Modelos relevantes** (ya existen, no se tocan):
- `Protocol` (`back/app/Models/Protocol.php`): `tasks()` HasMany `ProtocolTask` ordenado por `sort_order`.
- `ProtocolTask` (`back/app/Models/ProtocolTask.php`): `description, days_offset (int >= 0), time_of_day ('before'|'after'), time, important, sort_order`. Relación `alerts()` HasMany `ProtocolTaskAlert` ordenado por `sort_order`. `days_offset`/`time_of_day` son relativos a la **fecha base** elegida por el usuario.
- `ProtocolTaskAlert` (`back/app/Models/ProtocolTaskAlert.php`): `offset_days (int >= 0, default 0), time_of_day ('before'|'after', default 'before'), time, roles (array), message, require_confirmation, sort_order`. `offset_days`/`time_of_day` son relativos a la **fecha de la tarea** (no de la fecha base), ver DEC-NEG-05.

**Frontend:**
- `TechniqueProtocolsTab.vue` (`front/src/modules/techniques/components/`) renderiza la tabla de protocolos vía `BaseDataTable`. Acciones por fila en `BaseTableActions`, cada una un `BaseButton variant="row-action" size="small"` con `tooltip` e ícono, envuelta en `PermissionGuard` (Editar → `protocols.update`, Replicar → `protocols.create`, Eliminar → `protocols.delete`).
- Patrón de drawer: `ProtocolFormDrawer.vue` (`front/src/modules/protocols/components/`) usa `BaseDrawer` + `vee-validate` (`useForm`/`defineField`) + `toTypedSchema(zodSchema)`.
- No hay ningún `a-date-picker` usado hoy en el frontend — este sería el primer lugar que lo introduce.

**Backend:**
- No existe ningún servicio de cálculo de cronograma. `ProtocolService.php` (`back/app/Services/`) solo tiene CRUD + `replicate()`.
- Precedente de patrón a seguir (mismo controller, mismo archivo de rutas): `.claude/docs/plans/protocolos-replicar-plan.md`, que agregó `POST /v1/admin/protocols/{guid}/replicate` en `AdminProtocolController` + `back/routes/api/protocols.php`, protegido con permisos `protocols.*`.

## Decisiones tomadas (no negociables)

### DEC-NEG-01 — Es un cálculo de solo lectura, sin persistencia
El endpoint no crea, modifica ni elimina ningún registro en `protocol_tasks`, `protocol_task_alerts`, ni ninguna otra tabla (a diferencia de `programs`, que sí instancia protocolos con fechas reales — eso es un flujo distinto y no se toca acá). Es puro cálculo sobre los datos ya existentes del protocolo, devuelto en la respuesta.

### DEC-NEG-02 — Endpoint nuevo: `GET /v1/admin/protocols/{guid}/simulate`
Se agrega en `AdminProtocolController` (mismo controller que `replicate()`), registrado en `back/routes/api/protocols.php` bajo el mismo grupo de middleware/permiso que el resto de acciones de protocolo (`protocols.update` — ver DEC-NEG-06). Recibe la fecha base como query param `?base_date=YYYY-MM-DD`. Se elige `GET` en vez de `POST` porque es una operación idempotente de solo lectura (no efectos secundarios), consistente con el resto de endpoints `index`/`show` del recurso.

### DEC-NEG-03 — Fórmula de cálculo
- `fecha_tarea = base_date ± task.days_offset` (suma si `task.time_of_day = 'after'`, resta si `'before'`), con hora = `task.time`.
- `fecha_alerta = fecha_tarea ± alert.offset_days` (suma si `alert.time_of_day = 'after'`, resta si `'before'`), con hora = `alert.time`. Es relativa a la fecha de la tarea calculada en el paso anterior, NO a `base_date` directamente.
- Ambas tablas del resultado (tareas y alertas) se devuelven ordenadas cronológicamente por la fecha/hora calculada.

### DEC-NEG-04 — Nuevo método en `ProtocolService`: `simulate(Protocol $protocol, Carbon $baseDate): array`
Sigue el mismo patrón que `replicate()` en el mismo service. Devuelve una estructura con dos colecciones: `tasks` (cada una con `description, important, computed_date, computed_time` y su sub-lista de `alerts` con `message, roles, require_confirmation, computed_date, computed_time`) y opcionalmente una lista plana `alerts` a nivel protocolo si el frontend prefiere una sola tabla — el `arquitecto` define el shape exacto del Resource/JSON, esta es la lógica de cálculo mínima requerida.

### DEC-NEG-05 — Resolución de `roles` a etiquetas legibles
`ProtocolTaskAlert.roles` es un array de códigos de rol. Ya existe `getRoleLabel()` en el frontend (confirmado en exploración previa) para mapear roles a etiquetas — se reutiliza esa función para la columna "Destinatarios" del drawer. No se resuelve a usuarios reales asignados (esto es una simulación sobre la plantilla de protocolo, no sobre un programa activo con destinatarios concretos).

### DEC-NEG-06 — Resaltado visual de filas críticas
En ambas tablas del drawer se resaltan visualmente (highlight, tratamiento visual diferenciado del resto):
- Filas de Tareas donde `important = true`.
- Filas de Alertas donde `require_confirmation = true`.

El `arquitecto` define el detalle visual exacto (color/ícono), la regla de negocio de QUÉ se resalta no es negociable.

### DEC-NEG-07 — Permiso reutilizado, sin permiso nuevo
El botón "Simular" y el endpoint usan el permiso `protocols.update` ya existente (mismo criterio que "Replicar" reutiliza `protocols.create`: no se crea un permiso `protocols.simulate` para una acción de solo lectura sobre un recurso que ya se puede editar).

## Restricciones
- No modifica el significado de `days_offset`/`offset_days`/`time_of_day` en ningún otro flujo (esto es puro cálculo de exhibición).
- No debe disparar alertas reales, jobs, notificaciones, ni tocar el flujo de `programs` (instancias reales de protocolos con fechas).
- El date-picker es el primero del frontend — usar el componente Ant Design Vue estándar (`a-date-picker`), sin crear un átomo custom salvo que el `arquitecto` detecte necesidad de reutilización futura.

## Investigación previa que el arquitecto debe hacer
1. Definir el shape exacto de la respuesta del endpoint `simulate` (Resource/DTO) para que el frontend arme las dos tablas sin transformación compleja en el cliente.
2. Confirmar el formato exacto de query param de fecha (`base_date`) y su validación (Form Request con `date` requerido).
3. Definir dónde vive la lógica de highlight en el frontend (prop computada en la fila de `BaseDataTable`, o clase condicional directa).

## Output esperado
Plan en `.claude/docs/plans/TKT-004-simular-fecha-protocolo-plan.md`
