# Planes sanitarios (tenant Vet) — Guía de integración para Mobile

Documento para replicar en mobile la funcionalidad de **planes sanitarios** que hoy existe en el panel web del tenant Vet.

Alcance:

- Planes sanitarios por establecimiento: listar, crear, ver detalle, cancelar y confirmar actividades.
- Plantillas de plan sanitario propias de la empresa (CRUD completo).
- Catálogos de solo lectura (categorías y actividades) para armar plantillas.
- Endpoints auxiliares (clientes y establecimientos) para elegir sobre qué establecimiento se crea un plan.

---

## 1. Convenciones generales

### Base URL y autenticación

- Prefijo de todos los endpoints: `/api/v1`. *(Confirmar con backend el host/prefijo `/api` del entorno.)*
- Autenticación con **Laravel Sanctum** (Bearer token):

```
Authorization: Bearer <access_token>
Accept: application/json
Content-Type: application/json
```

- El token se obtiene en `POST /v1/auth/login` (campo `access_token` de la respuesta). Expira según `sanctum.expiration` (por defecto 1440 min).
- ⚠️ **Cada login revoca todos los tokens anteriores del usuario**: iniciar sesión en mobile cierra la sesión web del mismo usuario, y viceversa.
- `Accept: application/json` es obligatorio: sin él los errores no se devuelven como JSON.

### Tenant (`{vet}`)

Todos los endpoints de este documento cuelgan de `/v1/vets/{vet}/...`, donde `{vet}` es el **guid de la empresa** (UUID). El backend verifica que:

| Condición | Respuesta |
|---|---|
| Empresa inexistente | `404` `Empresa no encontrada.` |
| Empresa no validada o suspendida | `403` `Empresa inactiva.` |
| El usuario no tiene perfil en esa empresa | `403` `Sin acceso a esta empresa.` |
| Perfil bloqueado | `403` `Tu acceso a esta empresa está bloqueado.` |

### Identificadores

Todos los recursos se identifican por **`guid` (UUID)**. Nunca se exponen ni se aceptan IDs numéricos.

### Formato de respuesta

Éxito:

```json
{ "success": true, "data": { }, "message": "opcional" }
```

Éxito paginado (`data` contiene un objeto paginado):

```json
{
  "success": true,
  "data": {
    "data": [ ],
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

Error:

```json
{ "success": false, "message": "Texto legible", "errors": { } }
```

Errores de validación (`422`): `errors` es un mapa `campo → [mensajes]`.

```json
{
  "success": false,
  "message": "El año es requerido. (and 1 more error)",
  "errors": { "year": ["El año es requerido."] }
}
```

Errores de negocio (`422`/`403`): `errors` trae un `reason` estable que mobile puede usar para ramificar lógica (ver tabla en la sección 8).

Fechas: `date` → `YYYY-MM-DD`; `datetime` → ISO 8601 UTC (`2026-10-09T15:50:50.000000Z`).

### Paginación

Parámetros comunes: `page` (≥1) y `per_page` (1–100, default 15).

---

## 2. Permisos por rol

Cada endpoint exige un permiso. Mobile debería ocultar/deshabilitar acciones según los permisos del usuario (se leen de `GET /v1/auth/profile`).

| Permiso | vet | vet-assistant | vet-administrative |
|---|:-:|:-:|:-:|
| `establishment-health-plans.read` (listar/ver planes, plantillas, catálogos) | ✅ | ✅ | ✅ |
| `establishment-health-plans.create` (crear plan) | ✅ | ✅ | ✅ |
| `establishment-health-plans.update` (cancelar plan) | ✅ | ✅ | ✅ |
| `establishment-health-plans.confirm` (confirmar actividad) | ✅ | ✅ | ❌ |
| `establishment-health-plans.templates.create` | ✅ | ✅ | ❌ |
| `establishment-health-plans.templates.update` | ✅ | ✅ | ❌ |
| `establishment-health-plans.templates.delete` | ✅ | ✅ | ❌ |

Sin el permiso requerido la API responde `403`.

> Los roles `client-owner` y `client-manager` también tienen permiso de confirmación en el backend, pero hoy no existe portal de autenticación tenant para ellos.

---

## 3. Planes sanitarios

Un **plan sanitario** es la instancia de una plantilla sobre un establecimiento para un año. Al crearlo, el backend materializa una **actividad** por cada mes definido en la plantilla.

### 3.1 Listar planes

`GET /v1/vets/{vet}/establishment-health-plans` — permiso `establishment-health-plans.read`

Query params (todos opcionales):

| Param | Tipo | Descripción |
|---|---|---|
| `client_id` | uuid | Filtra por guid de cliente |
| `establishment_id` | uuid | Filtra por guid de establecimiento |
| `cancelled` | boolean | `true` solo cancelados, `false` solo activos; omitido = todos |
| `search` | string (≤255) | Búsqueda de texto |
| `page`, `per_page` | int | Paginación |

Ejemplo: `GET /v1/vets/9c1f…/establishment-health-plans?cancelled=false&per_page=20`

Respuesta `200`:

```json
{
  "success": true,
  "data": {
    "data": [
      {
        "guid": "0b5e2a0c-6c2a-4a35-9d57-5a0d4f1d9a11",
        "client": { "guid": "7f0c…", "name": "Estancia La Esperanza" },
        "establishment": { "guid": "a1b2…", "name": "Establecimiento Norte" },
        "template": { "guid": "c3d4…", "name": "Plan Ganadero Anual" },
        "year": 2026,
        "starts_on": "2026-01-01",
        "ends_on": "2026-12-31",
        "cancelled_at": null,
        "editable": true,
        "activities_count": 8,
        "pending_count": 5,
        "created_at": "2026-10-09T15:50:50.000000Z"
      }
    ],
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

`editable` es `false` cuando el plan está cancelado.

### 3.2 Crear plan

`POST /v1/vets/{vet}/establishment-health-plans` — permiso `establishment-health-plans.create`

Body:

| Campo | Tipo | Reglas |
|---|---|---|
| `establishment_id` | uuid | requerido; guid de un establecimiento de un cliente vinculado a la empresa |
| `health_plan_template_id` | uuid | requerido; guid de plantilla (propia o global) |
| `year` | integer | requerido; entre `2020` y `año actual + 1` |

```json
{
  "establishment_id": "a1b2c3d4-0000-4000-8000-000000000001",
  "health_plan_template_id": "c3d4e5f6-0000-4000-8000-000000000002",
  "year": 2026
}
```

El cliente se deduce del establecimiento; **no se envía `client_id`**.

Respuesta `201` (`message`: `Plan sanitario creado correctamente.`): mismo shape que el detalle (3.3).

Errores:

| Código | Caso |
|---|---|
| `422` | Validación: guid inexistente, año fuera de rango, el establecimiento no pertenece a la empresa, el cliente no tiene país configurado |
| `422` | `errors.health_plan_template_id`: `Ya existe un plan activo para este establecimiento, plantilla y año.` |
| `422` | Carrera de concurrencia: `errors.reason = "duplicate_plan"` |

Regla de duplicados: un plan está duplicado si ya existe uno **no cancelado** con la misma tupla (establecimiento, plantilla, año). Cancelar el plan existente permite crear otro.

#### Año y fechas de las actividades

`starts_on` / `ends_on` dependen del país del cliente (año ganadero). Ejemplo: en Argentina el año ganadero puede no coincidir con el calendario. Mobile **no calcula fechas**: usa `starts_on`, `ends_on` y `due_date` que devuelve el backend.

### 3.3 Detalle de un plan

`GET /v1/vets/{vet}/establishment-health-plans/{guid}` — permiso `establishment-health-plans.read`

Respuesta `200`: igual que un ítem del listado más `activities`, ordenadas por `sort_order` y luego `month`.

```json
{
  "success": true,
  "data": {
    "guid": "0b5e2a0c-6c2a-4a35-9d57-5a0d4f1d9a11",
    "client": { "guid": "7f0c…", "name": "Estancia La Esperanza" },
    "establishment": { "guid": "a1b2…", "name": "Establecimiento Norte" },
    "template": { "guid": "c3d4…", "name": "Plan Ganadero Anual" },
    "year": 2026,
    "starts_on": "2026-01-01",
    "ends_on": "2026-12-31",
    "cancelled_at": null,
    "editable": true,
    "activities_count": 3,
    "pending_count": 2,
    "created_at": "2026-10-09T15:50:50.000000Z",
    "activities": [
      {
        "guid": "e5f6…",
        "health_activity": { "guid": "1111…", "name": "Vacunación Aftosa" },
        "month": 10,
        "due_date": "2026-10-01",
        "status": "pending",
        "require_confirmation": true,
        "confirmed_at": null,
        "sort_order": 0
      },
      {
        "guid": "f6a7…",
        "health_activity": { "guid": "2222…", "name": "Desparasitación" },
        "month": 2,
        "due_date": "2026-02-01",
        "status": "confirmed",
        "require_confirmation": true,
        "confirmed_at": "2026-02-03T13:10:00.000000Z",
        "confirmed_by": { "guid": "9999…", "name": "Lucía Pérez" },
        "sort_order": 1
      }
    ]
  }
}
```

Notas:

- `status` es `pending` o `confirmed` (derivado de `confirmed_at`).
- `confirmed_by` aparece **solo** cuando la actividad ya está confirmada.
- Una misma actividad de catálogo puede repetirse en varios meses (una fila por mes).

Error `404`: `Plan sanitario no encontrado.` (también si el plan es de otra empresa).

### 3.4 Cancelar un plan

`POST /v1/vets/{vet}/establishment-health-plans/{guid}/cancel` — permiso `establishment-health-plans.update`

Sin body. Respuesta `200` (`message`: `Plan sanitario cancelado correctamente.`) con el plan; `cancelled_at` con valor y `editable: false`.

Efectos: se eliminan las alertas pendientes del plan (ver sección 7). Las actividades ya confirmadas se conservan.

Errores:

| Código | Caso |
|---|---|
| `404` | Plan no encontrado |
| `422` | `errors.reason = "not_editable"` — el plan ya estaba cancelado |

### 3.5 Confirmar una actividad

`POST /v1/vets/{vet}/establishment-health-plans/{guid}/activities/{activityGuid}/confirm` — permiso `establishment-health-plans.confirm`

Sin body. `{activityGuid}` es el `guid` de la actividad dentro del plan (no el de `health_activity`).

Respuesta `200` (`message`: `Actividad confirmada correctamente.`):

```json
{
  "success": true,
  "message": "Actividad confirmada correctamente.",
  "data": {
    "guid": "e5f6…",
    "health_activity": { "guid": "1111…", "name": "Vacunación Aftosa" },
    "month": 10,
    "due_date": "2026-10-01",
    "status": "confirmed",
    "require_confirmation": true,
    "confirmed_at": "2026-10-09T16:00:00.000000Z",
    "confirmed_by": { "guid": "9999…", "name": "Lucía Pérez" },
    "sort_order": 0
  }
}
```

Reglas:

- **Idempotente**: confirmar una actividad ya confirmada devuelve `200` con el estado actual, sin error y sin cambiar `confirmed_at`.
- No hay "des-confirmar".
- Roles permitidos: `vet`, `vet-assistant` (y `client-owner`/`client-manager` solo sobre establecimientos a los que estén vinculados).

Errores:

| Código | Caso |
|---|---|
| `403` | `errors.reason = "role_not_allowed"` |
| `404` | `Plan sanitario no encontrado.` / `Actividad no encontrada.` |

---

## 4. Plantillas de plan sanitario

Hay dos tipos de plantilla:

- **Globales** (`is_own: false`, `vet_guid: null`): creadas por el administrador de la plataforma. Solo lectura para la empresa.
- **Propias** (`is_own: true`): creadas por la empresa. Se pueden editar/eliminar **mientras no tengan planes instanciados** (`is_locked: false`).

### 4.1 Listar plantillas

`GET /v1/vets/{vet}/health-plan-templates` — permiso `establishment-health-plans.read`

| Param | Tipo | Descripción |
|---|---|---|
| `scope` | `own` \| `global` \| `all` | Default `all` (propias + globales). Las propias salen primero, luego por nombre |
| `search` | string (≤100) | Busca por nombre (`LIKE`) |
| `health_plan_category_guid` | uuid | Filtra por categoría |
| `page`, `per_page` | int | Paginación |

Respuesta `200` (ítems de `data.data`):

```json
{
  "guid": "c3d4…",
  "name": "Plan Ganadero Anual",
  "category": { "guid": "5a5a…", "name": "Bovinos" },
  "activities_count": 8,
  "vet_guid": "9c1f…",
  "is_own": true,
  "is_locked": false,
  "created_at": "2026-09-01T12:00:00.000000Z",
  "updated_at": "2026-09-01T12:00:00.000000Z"
}
```

### 4.2 Detalle de plantilla

`GET /v1/vets/{vet}/health-plan-templates/{guid}` — permiso `establishment-health-plans.read`

Incluye `activities` con los meses:

```json
{
  "success": true,
  "data": {
    "guid": "c3d4…",
    "name": "Plan Ganadero Anual",
    "category": { "guid": "5a5a…", "name": "Bovinos" },
    "activities": [
      { "guid": "1111…", "name": "Vacunación Aftosa", "months": [4, 10] },
      { "guid": "2222…", "name": "Desparasitación", "months": [2, 8] }
    ],
    "activities_count": 2,
    "vet_guid": "9c1f…",
    "is_own": true,
    "is_locked": false,
    "created_at": "2026-09-01T12:00:00.000000Z",
    "updated_at": "2026-09-01T12:00:00.000000Z"
  }
}
```

`404`: `Plantilla no encontrada.`

### 4.3 Crear plantilla

`POST /v1/vets/{vet}/health-plan-templates` — permiso `establishment-health-plans.templates.create`

| Campo | Tipo | Reglas |
|---|---|---|
| `name` | string | requerido, máx. 255 |
| `health_plan_category_guid` | uuid | requerido, debe existir |
| `activities` | array | opcional |
| `activities[].health_activity_guid` | uuid | requerido, debe existir |
| `activities[].months` | int[] | requerido, mínimo 1 elemento, cada uno de 1 a 12 |

```json
{
  "name": "Plan Ganadero Anual",
  "health_plan_category_guid": "5a5a5a5a-0000-4000-8000-000000000001",
  "activities": [
    { "health_activity_guid": "11111111-0000-4000-8000-000000000001", "months": [4, 10] },
    { "health_activity_guid": "22222222-0000-4000-8000-000000000002", "months": [2, 8] }
  ]
}
```

Respuesta `201` (`message`: `Plantilla creada correctamente.`): shape del detalle (4.2). La plantilla queda asociada a la empresa del `{vet}`.

### 4.4 Editar plantilla

`PUT /v1/vets/{vet}/health-plan-templates/{guid}` — permiso `establishment-health-plans.templates.update`

Mismo body que crear. **Es un reemplazo completo** (no PATCH): `name` y `health_plan_category_guid` son obligatorios, y las `activities` enviadas **reemplazan** a las existentes.

Respuesta `200` (`message`: `Plantilla actualizada correctamente.`).

Errores:

| Código | Caso |
|---|---|
| `404` | Plantilla no encontrada, o no es propia de la empresa (las globales no se pueden editar) |
| `422` | `errors.reason = "template_locked"` — la plantilla ya tiene planes instanciados |

### 4.5 Eliminar plantilla

`DELETE /v1/vets/{vet}/health-plan-templates/{guid}` — permiso `establishment-health-plans.templates.delete`

Respuesta `200` (`message`: `Plantilla eliminada correctamente.`, `data: null`).

Errores: `404` y `422 template_locked`, igual que editar.

> Regla UX sugerida: si `is_own = false` o `is_locked = true`, mostrar la plantilla solo en lectura y ocultar editar/eliminar.

---

## 5. Catálogos (solo lectura)

Catálogos globales para armar plantillas. La empresa **no** los crea ni edita. Permiso `establishment-health-plans.read`.

### 5.1 Categorías

`GET /v1/vets/{vet}/health-plan-categories` — admite `page` y `per_page`. *(Confirmar con backend si admite además `search`.)*

```json
{
  "guid": "5a5a…",
  "name": "Bovinos",
  "description": "Planes para ganado bovino",
  "templates_count": 4,
  "created_at": "2026-01-10T12:00:00.000000Z",
  "updated_at": "2026-01-10T12:00:00.000000Z"
}
```

### 5.2 Actividades

`GET /v1/vets/{vet}/health-activities` — admite `page` y `per_page`. *(Confirmar con backend si admite además `search`.)*

```json
{
  "guid": "1111…",
  "name": "Vacunación Aftosa",
  "description": "Aplicación de vacuna antiaftosa",
  "created_at": "2026-01-10T12:00:00.000000Z",
  "updated_at": "2026-01-10T12:00:00.000000Z"
}
```

---

## 6. Endpoints auxiliares para crear un plan

Para elegir el establecimiento sobre el que se crea el plan, mobile usa los endpoints de clientes y establecimientos del tenant (documentados en el módulo de clientes):

| Endpoint | Permiso | Uso |
|---|---|---|
| `GET /v1/vets/{vet}/clients` (`search`, `per_page`, `page`) | `clients.read` | Listar clientes de la empresa |
| `GET /v1/vets/{vet}/clients/{client}/establishments` | `establishments.read` | Listar establecimientos de un cliente (`guid`, `name`, `renspa`, ubicación, etc.) |

El `guid` del establecimiento elegido es el valor de `establishment_id` al crear el plan.

---

## 7. Alertas / notificaciones del plan

Al crear un plan, el backend genera alertas automáticamente. **Mobile no crea alertas**: solo las recibe por los canales de notificación.

- Se crea **una alerta por mes distinto** con actividades (no una por actividad).
- Programación: **7 días antes del inicio del mes, a las 16:00 hora local de la empresa** (zona horaria de su país).
- Si el mes ya está en curso y esa fecha ya pasó, la alerta se envía **de inmediato** (se despacha en el siguiente minuto).
- Los meses ya terminados no generan alerta.
- Destinatarios: perfiles con rol `vet` de la empresa. Canal por defecto: WhatsApp, con fallback a email.
- Si todas las actividades del mes se confirman antes del envío, la alerta se suprime.
- Texto del mensaje (WhatsApp): `Hola {nombre}, en {mes} corresponde: {actividades} — plan "{plantilla}" ({categoría}) de {cliente}, {establecimiento}.`
- Al cancelar el plan se eliminan sus alertas pendientes.

Si mobile quiere recibir push por esta alerta, el registro de suscripciones push está en `routes/api/push-subscriptions.php` (fuera del alcance de este documento).

---

## 8. Catálogo de errores de negocio

| HTTP | `errors.reason` | Endpoint | Significado | Acción sugerida en mobile |
|---|---|---|---|---|
| 422 | `duplicate_plan` | `POST …/establishment-health-plans` | Ya existe un plan activo (carrera de concurrencia) | Avisar y refrescar listado |
| 422 | `not_editable` | `POST …/{guid}/cancel` | El plan ya estaba cancelado | Refrescar el plan |
| 403 | `role_not_allowed` | `POST …/{guid}/activities/{activityGuid}/confirm` | El rol no puede confirmar | Ocultar botón según permisos |
| 422 | `template_locked` | `PUT/DELETE …/health-plan-templates/{guid}` | La plantilla ya tiene planes | Mostrar solo lectura |
| 401 | — | cualquiera | Token ausente/vencido | Cerrar sesión y volver a login |
| 403 | — | cualquiera | Sin permiso, empresa inactiva o sin acceso | Mostrar `message` |
| 404 | — | detalle / cancelar / confirmar / plantillas | Recurso no encontrado o de otra empresa | Volver al listado |
| 422 | — | POST / PUT | Validación de campos | Mostrar `errors` por campo |
| 429 | — | login | Rate limit | Reintentar luego |

---

## 9. Flujos sugeridos

**Crear un plan**

1. `GET …/clients` → elegir cliente.
2. `GET …/clients/{client}/establishments` → elegir establecimiento.
3. `GET …/health-plan-templates?scope=all` → elegir plantilla.
4. Elegir año.
5. `POST …/establishment-health-plans`.
6. Navegar al detalle con el `guid` devuelto.

**Confirmar actividades**

1. `GET …/establishment-health-plans/{guid}`.
2. Para cada actividad `pending` y si el usuario tiene `establishment-health-plans.confirm`, mostrar la acción **Confirmar**.
3. `POST …/activities/{activityGuid}/confirm` y actualizar la fila con la respuesta.

**Crear una plantilla propia**

1. `GET …/health-plan-categories` y `GET …/health-activities` para poblar selectores.
2. Armar actividades con sus meses (1–12, al menos un mes por actividad).
3. `POST …/health-plan-templates`.

---

## 10. Pendientes a confirmar con backend

- Host y prefijo real de la API por entorno (dev / prod).
- Si los catálogos (`health-plan-categories`, `health-activities`) soportan `search`.
- Estrategia de push para la alerta `health_plan.month` en mobile.
