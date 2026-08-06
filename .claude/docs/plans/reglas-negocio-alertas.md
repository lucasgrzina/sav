# Reglas de negocio de Alertas — qué se dispara, cuándo y a quién

Requerimientos funcionales del módulo de notificaciones. Describe el **qué** (los orígenes de
alertas, su timing y sus destinatarios). El **cómo** (arquitectura, contratos, patrones) está en
[`arquitectura-notificaciones.md`](./arquitectura-notificaciones.md).

> Estas reglas provienen del comportamiento del sistema legado. Se documentan como requerimientos a
> reimplementar sobre la arquitectura nueva, NO como código a portar tal cual.

---

## 1. Modelo de dominio relevante

Las alertas nacen de tres entidades del dominio:

- **Program**: un programa sanitario derivado de un **Protocolo**. El protocolo define
  **ProtocolAlerts** (plantillas de alerta con `days_offset`, `time`/`time_of_day`, `roles`) y una
  **Technique** con un `target_date` de referencia.
- **HealthPlan**: plan de salud con actividades agendadas por mes, ligado a un Client,
  Establishment y categoría.
- **Event** (Agenda): evento suelto del panel Vet con `date`, `time`, `days_before`, `alert_time`.

Cada alerta tiene uno o más **destinatarios** (usuarios). Un mismo evento de dominio puede generar
varias alertas y cada alerta varios destinatarios.

Timezone de todos los cálculos: **`America/Argentina/Buenos_Aires`**.

---

## 2. Los orígenes de alertas

Hay 5 orígenes que generan alertas + 1 notificación directa fuera del motor de alertas.

### 2.1 Alta de Program → tareas del protocolo (`AlertType::ProgramTaskDue`)

- **Cuándo**: al crear un Program.
- **Qué genera**: por cada `ProtocolAlert` del protocolo elegido, una alerta.
- **`scheduled_at`**: `target_date` **± `days_offset`** a la hora `time` de la ProtocolAlert.
- **Regla de descarte**: si la fecha calculada **ya pasó**, la alerta **no se crea** (descarte
  silencioso, sin log).
- **Destinatarios**: los **managers** del programa cuyo rol esté incluido en `protocol_alert.roles`.
- **Payload**: `{ messages: [{message}], headerDay? }` — una o varias tareas (hasta 5).
- **Nota del legado**: al **editar** un Program NO se regeneran estas alertas (la regeneración
  estaba comentada). Decidir en el proyecto nuevo si se regeneran en edición o no.

### 2.2 Creación de Program → confirmación inmediata (`AlertType::ProgramCreated`)

- **Cuándo**: inmediatamente después de crear el Program.
- **`scheduled_at`**: `now()` (envío inmediato).
- **Destinatarios**: los managers del programa (según la implementación de creación).
- **Payload**: mínimo — solo se necesita el nombre del programa.
- En el legado esto era un `notify()` directo sin pasar por la tabla de alertas. En el diseño nuevo
  se unifica como una alerta inmediata más (mismo motor), salvo que se quiera mantener el envío
  síncrono.

### 2.3 Cancelación de Program (`AlertType::ProgramCancelled`)

- **Cuándo**: al cancelar un Program.
- **Efecto colateral**: **borrar las alertas pendientes** de ese programa (las `ProgramTaskDue` aún
  no enviadas).
- **`scheduled_at`**: `now()` (envío inmediato).
- **Destinatarios**: los managers actuales del programa.
- **Payload**: nombre del programa.

### 2.4 Alta/edición de HealthPlan (`AlertType::HealthPlanMonth`)

- **Cuándo**: al crear o editar un HealthPlan.
- **Qué genera**: una alerta **por cada mes** que tenga actividades futuras.
- **`scheduled_at`**: **primer día del mes − 7 días, a las 16:00**.
- **Destinatarios**: usuarios del vet con rol **`VET_VET`**.
- **Payload**: `{ month, activities }` (el mes y el detalle de actividades de ese mes).

### 2.5 Alta/edición de Evento de Agenda (`AlertType::EventReminder`)

- **Cuándo**: al crear o editar un Event en la Agenda (panel Vet).
- **`scheduled_at`**: **fecha del evento − `days_before` días**, a la hora `alert_time`.
- **Destinatarios**: el **usuario que crea/edita** el evento.
- **Payload**: nombre del evento, fecha+hora, cliente.

### 2.6 Registro de usuario (fuera del motor de alertas)

- Notificación directa por **email** con contraseña provisoria al dar de alta un usuario.
- No pasa por la tabla de alertas ni por el scheduler. En el diseño nuevo puede ser una
  `Notification` de Laravel normal o un canal `email` del mismo módulo, según se prefiera.

---

## 3. Tabla resumen

| AlertType | Origen | `scheduled_at` | Destinatarios |
|---|---|---|---|
| `program.task_due` | Alta de Program (por cada ProtocolAlert) | `target_date` ± `days_offset` @ `time`; descarta si ya pasó | managers con rol en `protocol_alert.roles` |
| `program.created` | Creación de Program | `now()` | managers del programa |
| `program.cancelled` | Cancelación de Program (+ borra pendientes) | `now()` | managers actuales |
| `health_plan.month` | Alta/edición de HealthPlan (por mes) | 1° del mes − 7 días @ 16:00 | usuarios del vet con rol `VET_VET` |
| `event.reminder` | Alta/edición de Event (Agenda) | fecha del evento − `days_before` @ `alert_time` | usuario que crea/edita |
| (email) | Registro de usuario | inmediato | el usuario dado de alta |

---

## 4. Catálogo de plantillas de WhatsApp (Twilio Content API)

**9 plantillas de WhatsApp Business** que hay que dar de alta y aprobar en la cuenta de Twilio (o
reescribir si se cambia de proveedor). El identificador es el `contentSid`; las variables se pasan
como `contentVariables` (`{"1": "...", ...}`).

> Los `contentSid` de abajo son los de la cuenta del sistema legado. Si el proyecto nuevo usa otra
> cuenta/proveedor de Twilio, **hay que re-aprobar las plantillas y reemplazar estos IDs**. Se
> recomienda no hardcodearlos: guardarlos en `config` o en una tabla de mapeo.

### 4.1 `program.task_due` — 5 plantillas según cantidad de tareas

| # tareas | contentSid |
|---|---|
| 1 | `HXc36e957f1fb2e80045823abb257add3b` |
| 2 | `HXabf7c5e912af71c2a52ff175fae79563` |
| 3 | `HXf624fb6bcaccd55c849c5ebfb8348a0c` |
| 4 | `HX888c721be28db9ca32dbeb445ee5db8c` |
| 5 | `HX37d87fd15c92ddb0602a6deb8a31f2e6` |

Variables: `1`=nombre del cliente, `2`=nombre del destinatario, `3`=encabezado de fecha
(soporta placeholders `{{fecha}}` → hoy y `{{fecha+1}}` → mañana), `4..N`=cada mensaje/tarea,
`N+1`=nombre del programa, `N+2`=`target_date_name: dd-mm-YYYY`.

### 4.2 `program.created` — `HXf21519b33fc95a762315e0a9289c101c`

Variables: `1`=nombre del destinatario, `2`=nombre del programa.

### 4.3 `program.cancelled` — `HXbf82a4e83175759cf88d9ab5252a7cbd`

Variables: `1`=nombre del destinatario, `2`=nombre del programa.

### 4.4 `health_plan.month` — `HXb734a385bc5cdc6bbe1dfac9dee85fb7`

Variables: `1`=nombre del destinatario, `2`=mes, `3`=actividades, `4`=nombre del plan,
`5`=categoría del plan, `6`=cliente, `7`=establecimiento.

### 4.5 `event.reminder` — `HX8b44cd3f5741c6780d39d396e6d7dec3`

Variables: `1`=nombre del destinatario, `2`=nombre del evento, `3`=`dd-mm-YYYY HH:mm`,
`4`=nombre del cliente.

---

## 5. Opt-out / opt-in (webhook entrante)

- Los usuarios pueden **darse de baja** respondiendo `BAJA` por WhatsApp, y **re-suscribirse** con
  `ALTA`. El proveedor reenvía esa respuesta al webhook entrante.
- El opt-out debe **respetarse antes de cada envío** (política `OptOutPolicy` del pipeline, ver doc
  de arquitectura). En el diseño nuevo el opt-out es **por canal**.
- **Normalización de número AR**: WhatsApp envía el número como `549…`; el número real es `54…`
  (hay que quitar el `9`). Guardar siempre normalizado y en formato E.164 sin `+`.

---

## 6. Inconsistencias del legado a resolver (decisiones para el proyecto nuevo)

Puntos donde el sistema viejo era inconsistente; conviene unificarlos en el rediseño:

1. **Formato de teléfono**: algunas notificaciones usaban `phone` (`54-11-…`, sin `+`) y otras
   `formated_phone` (con `+`). **Decisión recomendada**: un único formato E.164 normalizado en el
   `Recipient`.
2. **`ProgramCreated` fuera del motor**: era un `notify()` directo. **Decisión recomendada**:
   unificarlo como alerta inmediata en el mismo motor, salvo que se necesite envío 100% síncrono.
3. **Edición de Program no regeneraba alertas**. **Decisión**: definir si en el proyecto nuevo la
   edición regenera las `program.task_due` pendientes.
4. **`delivered_at` por alerta, no por destinatario** (bug del legado). Ya resuelto en el diseño
   nuevo con la tabla `alert_recipients`.
5. **Opt-out solo en el canal Twilio**: si se agrega otro canal, el opt-out debe seguir aplicándose.
   Ya resuelto: vive en el `DeliveryPipeline`, transversal a todos los canales.
