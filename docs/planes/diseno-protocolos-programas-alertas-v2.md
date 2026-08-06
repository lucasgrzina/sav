# Diseño: Protocolos, Programas y Alertas — Especificación para el proyecto nuevo (API v2)

> Reemplaza el esquema actual de `protocol_alerts` / `tasks` para el backend Laravel 12 API pura. Objetivo: que una alerta quede explícitamente vinculada a la tarea que la origina (hoy no lo está), que el veterinario pueda elegir destinatarios concretos por tarea al crear un programa, y que pueda agregar/editar una alerta puntual sin tocar la plantilla del protocolo.

## 0. Decisión de base: mantener la capa de despacho genérica

El proyecto actual ya tiene una capa de despacho polimórfica bien diseñada y reutilizada por `Program`, `HealthPlan` y `Event`:

- **`alerts`** (tabla genérica): `model_id`, `model_class`, `notification_class`, `text`, `send_at`, `delivered_at`, `require_confirmation`, `confirmed_at`.
- **`alert_user`** (pivot): destinatarios reales de cada alerta.
- **`HasAlertsTrait`**: da `alerts()`, `getAlerts()`, `removeAlerts()` a cualquier modelo que lo use.
- **`SendAlertsNotifications`** (job): escanea `alerts` con `send_at <= now() AND delivered_at IS NULL`, sin importar de qué modelo vinieron, y despacha `notify(new $alert->notification_class($alert))` a cada recipient.

Esta capa **se conserva sin cambios**. Las tablas nuevas de este diseño no duplican `send_at`/`delivered_at`/destinatarios — se enganchan a `alerts` igual que `Program`/`HealthPlan`/`Event` lo hacen hoy, vía un modelo intermedio (`ProgramTaskAlert`) que usa el mismo `HasAlertsTrait`.

## 1. Principio de diseño

Dos capas temporales:
- **Plantilla** (`protocols`, `protocol_tasks`, `protocol_task_alerts`): reutilizable, editable, la crean SuperAdmin o Vet.
- **Instancia** (`programs`, `program_tasks`, `program_task_alerts`): snapshot congelado al crear el programa. Cambiar el protocolo después no afecta programas ya creados.

`important` (booleano en tareas) es un concepto **separado** de si la tarea dispara alertas: se conserva igual que hoy, solo para negrita en PDF/listado. Que una tarea tenga o no alertas configuradas se determina por si existen filas hijas en `protocol_task_alerts` — no hace falta un flag adicional tipo `is_notifiable`.

## 2. Capa plantilla

### `techniques` (sin cambios)
```
id
name
parent_id          FK techniques, nullable  -- null = técnica raíz
target_date_name   nullable
type                default 'technique'
```

### `protocols`
```
id
technique_id       FK techniques
vet_id             FK vets, nullable        -- null = global (visible a todos los vets)
created_by_type    enum('superadmin','vet') -- auditoría de origen
created_by_id      unsignedBigInteger
name
color
deleted_at         (soft delete)
```

### `protocol_tasks`
```
id
protocol_id        FK protocols
description
days_offset        int
time_of_day        enum('before','after')
time
important          bool default false       -- SE CONSERVA: uso en generación de PDF, sin relación con alertas
sort_order          int default 0
```

### `protocol_task_alerts`
```
id
protocol_task_id      FK protocol_tasks       -- vínculo explícito con la tarea (esto es lo que faltaba en protocol_alerts)
offset_days             int default 0          -- relativo a la fecha de la tarea: -1 = día antes, 0 = mismo día
time_of_day              enum('before','after') default 'before'
time
roles                     json                  -- roles sugeridos por defecto (RolesEnum)
message                   text
require_confirmation      bool default false
sort_order                 int default 0
```

Puede haber **N filas por tarea**. Ejemplo — tarea "Retirar CIDR" con dos alertas: `offset_days=-1, time=08:00` (día antes) y `offset_days=0, time=08:00` (mismo día).

## 3. Capa instancia

### `programs` (sin cambios estructurales)
```
id, vet_id, client_id, establishment_id, group_id, technique_id, protocol_id, target_date, state, comments
```

### `program_tasks` (reemplaza la actual `tasks`)
```
id
program_id          FK programs
protocol_task_id    FK protocol_tasks, nullable  -- null si es tarea ad-hoc agregada a mano
description
date, time                                        -- ya materializado desde target_date ± days_offset
important             bool                          -- copiado de protocol_tasks al crear el programa
completed_at
```

### `program_task_alerts` (tabla de vínculo liviana, NO duplica despacho)
```
id
program_task_id            FK program_tasks
protocol_task_alert_id     FK protocol_task_alerts, nullable   -- null = alerta custom agregada por el vet para esa instancia
```

Usa `HasAlertsTrait` (igual que `Program`, `HealthPlan`, `Event`) → por cada fila acá se crea **una** fila en `alerts` (`model_class = ProgramTaskAlert::class`, `model_id = program_task_alerts.id`) con `send_at` calculado, `text`, `require_confirmation`, y los destinatarios elegidos van al pivot `alert_user` existente. No se crea una tabla de destinatarios nueva — se reutiliza la que ya existe.

## 4. Diagrama de relaciones

```
protocols ──< protocol_tasks ──< protocol_task_alerts
    │ (important se copia)              │ (plantilla, opcional)
    ▼                                    ▼
programs ──< program_tasks ──< program_task_alerts ──(HasAlertsTrait)──< alerts >──< alert_user >── users
```

## 5. Flujo funcional

### Crear protocolo (SuperAdmin o Vet — mismo endpoint, `vet_id` según contexto de auth)
```
POST /api/v2/protocols
{
  technique_id, name,
  tasks: [
    {
      description, days_offset, time_of_day, time, important,
      alerts: [
        { offset_days, time_of_day, time, roles, message, require_confirmation }
      ]
    }
  ]
}
```
Todo se crea en una transacción (protocolo + tareas + alertas de plantilla anidadas).

### Crear programa (Vet)
1. `GET /api/v2/protocols/{id}?with=tasks.alerts` — el front trae el protocolo completo para el wizard.
2. El front lista las tareas; las que tienen `protocol_task_alerts` muestran esas alertas sugeridas, con los roles ya resueltos contra los `managers` elegidos para el programa:
   `GET /api/v2/protocols/{id}/suggested-recipients?managers[]=...`
3. El vet ajusta destinatarios por alerta, y opcionalmente:
   - agrega una alerta custom para una tarea puntual (mismo formato, sin `protocol_task_alert_id`),
   - edita el mensaje/horario de una alerta sugerida solo para esta instancia (no toca la plantilla).
4. `POST /api/v2/programs` — payload base del programa + `task_alerts_overrides` (por `protocol_task_id`: destinatarios elegidos, alertas agregadas/eliminadas). El backend:
   - crea `program_tasks` con fechas reales,
   - por cada `protocol_task_alerts` de las tareas involucradas crea `program_task_alerts` + su `Alert` (vía `HasAlertsTrait`) con destinatarios resueltos (override del front o managers que matchean el rol sugerido),
   - agrega las alertas custom que mandó el front de la misma forma.

### Envío (sin cambios)
`SendAlertsNotifications` sigue escaneando `alerts` genéricamente — no necesita saber que ahora el origen es `ProgramTaskAlert` en vez de `Program` directo.

## 6. Mapeo tabla vieja → tabla nueva

| Actual | Nueva | Nota |
|---|---|---|
| `protocol_tasks.important` | `protocol_tasks.important` | sin cambios |
| `protocol_alerts` | `protocol_task_alerts` | ahora con FK a `protocol_task_id` |
| `tasks` | `program_tasks` | mismo rol, agrega `protocol_task_id` |
| — (no existía) | `program_task_alerts` | vínculo tarea-instancia ↔ alerta de plantilla u custom |
| `alerts` / `alert_user` | sin cambios | se reutiliza tal cual |
| `Program::protocolTasks()` (`hasManyThrough`) | igual, pero ahora `program_tasks` ya trae `protocol_task_id` propio, no hace falta el `hasManyThrough` para saber el origen |

## 7. Qué problema resuelve cada cambio

| Problema en el sistema actual | Solución |
|---|---|
| `important` no disparaba ninguna alerta (confirmado: no se referencia en `app/Jobs/`) | Se separa completamente: `important` = solo PDF; alertas = filas explícitas en `protocol_task_alerts` |
| `protocol_alerts` no sabía de qué tarea era (offsets absolutos coincidentes "a ojo") | FK directa `protocol_task_id` en la plantilla y `program_task_id` en la instancia |
| Offsets duplicados a mano para "día antes" / "mismo día" de una misma tarea | `offset_days` relativo a la tarea — dos filas con el mismo `protocol_task_id` |
| Alerta = rol recalculado en cada envío, no persona fija | Destinatarios se fijan en `alert_user` al crear el programa (vía `Alert` generado desde `program_task_alerts`) |
| Sin forma de customizar una alerta puntual sin tocar el protocolo | `protocol_task_alert_id` nullable en `program_task_alerts` habilita alertas ad-hoc por instancia |
| Cambiar el protocolo después afectaba (o no, según el flujo) programas ya creados | Snapshot explícito: `program_tasks`/`program_task_alerts` quedan fijos al momento de crear el programa |
| Riesgo de reinventar el despacho (send_at/delivered_at/recipients) por dominio | Se reutiliza la capa genérica `alerts` + `alert_user` + `HasAlertsTrait` + `SendAlertsNotifications`, ya validada y usada por `HealthPlan`/`Event` |

## 8. Pendiente para cuando se implemente

- Definir `ProgramTaskAlertNotification` (o reusar `ProgramTaskNotification` renombrada) como la clase que arma el mensaje por canal (SMS/WhatsApp/Email) a partir del `Alert->text`.
- Decidir si `protocol_task_alerts.roles` se valida contra `RolesEnum` a nivel Form Request o DB enum.
- Confirmar si `program_task_alerts` necesita soft delete (para no perder trazabilidad si el vet elimina una alerta sugerida antes de confirmar el programa) o si al no confirmarse simplemente no se persiste.
