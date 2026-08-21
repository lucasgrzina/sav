# API para app mobile de vets — Circuito completo de creación de Programa

Spec para el equipo de la app mobile (PWA). Cubre todo el circuito que hoy existe en el panel web tenant de una veterinaria (VET) para crear un Programa: resolver sesión/tenant, elegir cliente/establecimiento/protocolo, armar los grupos target de animales, elegir los responsables que reciben las alertas, y crear/consultar el programa.

Todos los endpoints devuelven el envelope estándar del proyecto:

```json
{ "success": true, "data": { ... }, "message": "..." }
```

En error:

```json
{ "success": false, "message": "...", "errors": { "campo": ["..."] } }
```

Base URL: `/api`. Todos los ids en URL/body son **guid** (UUID), nunca ids numéricos internos.

**Corrección sobre una versión previa de este documento**: las rutas de auth NO son `/v1/register`, `/v1/login`, `/v1/profile`. Verificado contra `back/routes/api/auth.php`: van bajo el prefijo `/v1/auth/...` (`/v1/auth/register`, `/v1/auth/login`, `/v1/auth/profile`). Usar las rutas de este documento, no las de una spec anterior si la tenían distinta.

---

## 1. Autenticación

### 1.1 Login

`POST /v1/auth/login`

**Request**

```json
{
  "email": "ana.gomez@example.com",
  "password": "Passw0rd!"
}
```

**Response 200 — cuenta verificada**

```json
{
  "success": true,
  "data": {
    "access_token": "1|abcdef123456...",
    "user": {
      "id": 42,
      "guid": "b1e2c3d4-0001-4a2b-9c3d-0000000000aa",
      "first_name": "Ana",
      "last_name": "Gomez",
      "email": "ana.gomez@example.com",
      "last_login_at": "2026-08-21T10:00:00.000000Z",
      "roles": [{ "guid": "role-guid", "name": "vet-owner" }],
      "permissions": ["programs.read", "programs.create"]
    },
    "must_verify_account": false,
    "must_change_password": false
  }
}
```

**Response 200 — cuenta SIN verificar** (no hay `access_token`; no asumir que 200 = hay sesión)

```json
{
  "success": true,
  "data": {
    "must_verify_account": true,
    "user": { "guid": "...", "first_name": "Ana", "last_name": "Gomez", "email": "..." }
  }
}
```

**Response 422** — credenciales inválidas, con el mensaje trayendo intentos restantes o cuenta bloqueada.

Usar el token como Bearer en todos los endpoints siguientes:

```
Authorization: Bearer 1|abcdef123456...
```

### 1.2 Perfil del usuario autenticado

`GET /v1/auth/profile`

```json
{
  "success": true,
  "data": {
    "guid": "b1e2c3d4-...",
    "first_name": "Ana",
    "last_name": "Gomez",
    "email": "ana.gomez@example.com",
    "roles": [{ "guid": "role-guid", "name": "vet-owner" }],
    "permissions": ["programs.read", "programs.create"]
  }
}
```

### 1.3 Logout

`POST /v1/auth/logout` — revoca el token actual. Sin body. `{ "success": true, "message": "Sesión cerrada correctamente." }`

---

## 2. Resolver el tenant (vet)

`GET /v1/user/vets`

Todas las vets activas donde el usuario tiene perfil (multi-tenant real, no asumir una sola).

```json
{
  "success": true,
  "data": [
    {
      "guid": "vet-guid-001",
      "name": "Veterinaria San Martín",
      "slug": "veterinaria-san-martin",
      "logo_path": null,
      "is_active": true,
      "role": {
        "name": "vet-owner",
        "permissions": ["programs.read", "programs.create", "programs.update"]
      }
    }
  ]
}
```

Si `data` viene vacío: el usuario no tiene ninguna vet asignada. El `guid` elegido es el `{vet}` que va en el path de **todos** los endpoints siguientes.

Toda ruta `/v1/vets/{vet}/...` pasa por el middleware `vet.tenant` (`EnsureUserBelongsToVet`), que puede cortar antes de llegar al controller con:

- `403 { "message": "Veterinaria inactiva." }` — vet no validada o suspendida.
- `403 { "message": "Sin acceso a esta veterinaria." }` — el usuario no tiene `UserProfile` en esa vet.
- `403 { "message": "Tu acceso a esta veterinaria está bloqueado." }` — perfil bloqueado (`blocked_at`).
- `404 { "message": "Veterinaria no encontrada." }` — guid inexistente.

Además, cada acción individual exige permiso vía `can:<permiso>` (ej. `programs.create`), que si falta devuelve `403 { "message": "No autorizado." }`.

---

## 3. Datos de soporte para armar el formulario

El formulario de "crear programa" necesita, en este orden, resolver: cliente → establecimiento → protocolo → grupos target de animales → responsables (recipients de alertas). Estos son los endpoints que alimentan cada picker.

### 3.1 Clientes de la vet

`GET /v1/vets/{vet}/clients?search=&per_page=15`

Requiere `clients.read`.

```json
{
  "success": true,
  "data": {
    "data": [
      { "guid": "client-guid-1", "name": "Estancia La Loma", "tax_id": "30-12345678-9", "address": "...", "city": "...", "state": "...", "zip_code": "...", "created_at": "..." }
    ],
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

Nota: la paginación va **dentro** de `data` (`data.data`, `data.current_page`, etc.), no es el formato `{data:[...], meta:{...}}` de Laravel por default — todo el proyecto usa `makeSuccessPagination` con este shape propio.

Si el cliente todavía no está vinculado a la vet, existe `GET /v1/vets/{vet}/clients/lookup?tax_id=...` para buscarlo globalmente por CUIT y `POST /v1/vets/{vet}/clients/{guid}/link` para vincularlo — fuera del alcance de "crear programa" salvo que la app mobile también deba dar de alta clientes nuevos.

### 3.2 Establecimientos del cliente elegido

`GET /v1/vets/{vet}/clients/{client}/establishments`

Requiere `establishments.read`. Sin paginar (lista completa).

```json
{
  "success": true,
  "data": [
    { "guid": "estab-guid-1", "name": "Establecimiento Norte", "renspa": "01.234.5.67890/01", "address": "...", "city": "...", "state": "...", "zip_code": "...", "latitude": null, "longitude": null, "created_at": "..." }
  ]
}
```

### 3.3 Protocolos disponibles para la vet

`GET /v1/vets/{vet}/protocols?technique_id=&search=&per_page=15`

Requiere `protocols.read`. Devuelve tanto protocolos propios de la vet (`is_own: true`) como plantillas globales (`is_global: true`) — ambos son elegibles para `protocol_id` al crear el programa.

```json
{
  "success": true,
  "data": {
    "data": [
      {
        "guid": "protocol-guid-1",
        "name": "Protocolo Estándar",
        "color": "#1AE5A0",
        "technique": { "guid": "tech-guid-1", "name": "Inseminación" },
        "is_global": false,
        "is_own": true,
        "tasks_count": 4,
        "created_at": "..."
      }
    ],
    "current_page": 1, "last_page": 1, "per_page": 15, "total": 1
  }
}
```

`technique_id` del programa **no se envía** al crear: el backend lo deriva del protocolo elegido (`technique_id = protocol.technique_id`), así que la app no tiene que pedirlo por separado.

### 3.4 Animales del cliente (grupos target)

`GET /v1/vets/{vet}/clients/{client}/animals?search=<rp>`

Requiere `programs.read` (reusa este permiso, no tiene uno propio). Sirve tanto para autocompletar RPs existentes del cliente como para la búsqueda que decide si un animal ya existe o hay que crearlo al vuelo.

```json
{
  "success": true,
  "data": [
    { "guid": "animal-guid-1", "rp": "RP-4521", "name": null }
  ]
}
```

**Contrato clave para armar `targets[].animals[]` al crear/editar un programa** (ver §4.2):
- Si el animal existe y el usuario lo eligió del autocomplete → mandar `{ "id": "<guid>", "rp": "<rp>" }`. `rp` va **siempre**, incluso para animales existentes (el backend no lo re-consulta).
- Si el usuario tipeó un RP que no está en la lista (animal nuevo) → mandar `{ "id": null, "rp": "<rp-tipeado>" }`. El backend lo crea o reutiliza uno existente con ese RP para ese mismo cliente (`firstOrCreateForClient`).
- Si el `id` no pertenece al `client_id` del programa (o no existe), el backend lo **descarta en silencio** — ni error, ni se guarda ese animal, ni bloquea el resto del target. La app no tiene que prevalidar esto, pero tampoco puede confiar en que "no dio error" signifique "se guardó tal cual se mandó" — conviene releer el detalle después de guardar si la UI necesita confirmar el estado final.

### 3.5 Responsables / recipients de alertas (`manager_profile_ids`)

No hay un único endpoint "de managers": un responsable puede ser staff de la vet **o** staff del cliente del programa (DEC-12), y ambos son fuentes separadas que la app tiene que combinar en un solo picker.

**Staff de la vet:**

`GET /v1/vets/{vet}/staff`

**Staff del cliente elegido:**

`GET /v1/vets/{vet}/clients/{client}/staff`

Requiere `clients.staff.read`. Solo se puede pedir esto **después** de elegir cliente.

Ambos devuelven el mismo shape (`UserProfileResource`):

```json
{
  "success": true,
  "data": [
    {
      "guid": "profile-guid-1",
      "user": { "guid": "user-guid-1", "name": "Ana Gomez", "first_name": "Ana", "last_name": "Gomez", "email": "ana@example.com" },
      "role": { "guid": "role-guid-2", "name": "vet-owner" },
      "contacts": [],
      "blocked_at": null,
      "created_at": "..."
    }
  ]
}
```

Importante: ninguno de los dos endpoints devuelve un campo `origin` — la app tiene que taggear `origin: 'vet'` o `origin: 'client'` según de qué llamada vino cada item antes de mostrarlos juntos, porque `manager_profile_ids` en el payload de creación es una lista plana de guids sin distinguir procedencia. El campo `origin` sí aparece de vuelta en el detalle del programa ya creado (§4.3), calculado ahí por el backend comparando el rol contra `UserProfileService::VET_STAFF_ROLES`.

Filtrar `blocked_at !== null` en el cliente: un perfil bloqueado no debería ofrecerse como responsable (el backend no lo prohíbe explícitamente en la validación de `manager_profile_ids`, así que si se necesita bloquear esa selección es responsabilidad de la UI).

---

## 4. Programas

### 4.1 Listar programas

`GET /v1/vets/{vet}/programs?per_page=20&cancelled=0&client_id=&establishment_id=&technique_id=&search=`

Requiere `programs.read`.

```json
{
  "success": true,
  "data": {
    "data": [
      {
        "guid": "program-guid-100",
        "client": { "guid": "client-guid-1", "name": "Estancia La Loma" },
        "establishment": { "guid": "estab-guid-1", "name": "Establecimiento Norte" },
        "technique": { "guid": "tech-guid-1", "name": "Inseminación" },
        "protocol": { "guid": "protocol-guid-1", "name": "Protocolo Estándar" },
        "cancelled_at": null,
        "editable": true,
        "targets_count": 3,
        "next_target_date": "2026-08-20",
        "created_at": "2026-08-01T12:00:00.000000Z"
      }
    ],
    "current_page": 1, "last_page": 1, "per_page": 20, "total": 1
  }
}
```

### 4.2 Crear programa

`POST /v1/vets/{vet}/programs`

Requiere `programs.create`.

**Request**

```json
{
  "client_id": "client-guid-1",
  "establishment_id": "estab-guid-1",
  "protocol_id": "protocol-guid-1",
  "comments": "Programa de primavera",
  "targets": [
    {
      "target_date": "2026-08-20",
      "animals": [
        { "id": "animal-guid-1", "rp": "RP-4521" },
        { "id": null, "rp": "RP-9990" }
      ]
    },
    {
      "target_date": "2026-09-05",
      "animals": []
    }
  ],
  "manager_profile_ids": ["profile-guid-1", "profile-guid-2"]
}
```

Reglas duras que valida el backend (`StoreProgramRequest`, tiran 422 si se pisan):

- `client_id` tiene que pertenecer a la vet del path (`clients,guid` + relación vet-cliente), sino error en `client_id` y **corta ahí** (no valida el resto).
- `establishment_id` tiene que pertenecer a ese `client_id`.
- `protocol_id`, si tiene `vet_id` asignado (no es plantilla global), tiene que ser el de esta vet.
- `targets`: array, mínimo 1 (`must_have_one_target` es un error propio de `update`, no de `store` — en creación simplemente el `min:1` de Laravel).
- `targets[].target_date`: requerido, fecha válida.
- `targets[].animals[].rp`: requerido siempre, incluso con `id` presente.
- `manager_profile_ids`: array, mínimo 1, y cada guid tiene que resolver a un perfil que pertenezca a la vet **o** al cliente elegido — si no pertenece a ninguno de los dos, error puntual en `manager_profile_ids.<índice>`.

**Response 201**

```json
{
  "success": true,
  "message": "Programa creado correctamente.",
  "data": {
    "guid": "program-guid-100",
    "client": { "guid": "client-guid-1", "name": "Estancia La Loma" },
    "establishment": { "guid": "estab-guid-1", "name": "Establecimiento Norte" },
    "technique": { "guid": "tech-guid-1", "name": "Inseminación" },
    "protocol": { "guid": "protocol-guid-1", "name": "Protocolo Estándar" },
    "comments": "Programa de primavera",
    "cancelled_at": null,
    "editable": true,
    "targets": [
      {
        "guid": "target-guid-1",
        "target_date": "2026-08-20",
        "animals": [
          { "guid": "animal-guid-1", "rp": "RP-4521", "name": null },
          { "guid": "animal-guid-2", "rp": "RP-9990", "name": null }
        ]
      },
      { "guid": "target-guid-2", "target_date": "2026-09-05", "animals": [] }
    ],
    "managers": [
      { "guid": "profile-guid-1", "name": "Ana Gomez", "role": "vet-owner", "origin": "vet" },
      { "guid": "profile-guid-2", "name": "Carlos Diaz", "role": "client-owner", "origin": "client" }
    ],
    "created_at": "2026-08-21T12:00:00.000000Z",
    "updated_at": "2026-08-21T12:00:00.000000Z"
  }
}
```

**Response 422 — validación de negocio**

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": { "client_id": ["El cliente no pertenece a esta veterinaria."] }
}
```

### 4.3 Detalle de programa (con proyección de alertas)

`GET /v1/vets/{vet}/programs/{guid}`

Requiere `programs.read`. Esta es la única vista que trae, por cada target, la **simulación** de qué tareas y alertas del protocolo van a dispararse y quién las recibe — útil para que la app mobile muestre "esto es lo que va a pasar" antes/después de crear el programa, sin que eso implique que ya se generaron notificaciones reales (es de solo lectura).

```json
{
  "success": true,
  "data": {
    "guid": "program-guid-100",
    "client": { "guid": "client-guid-1", "name": "Estancia La Loma" },
    "establishment": { "guid": "estab-guid-1", "name": "Establecimiento Norte" },
    "technique": { "guid": "tech-guid-1", "name": "Inseminación" },
    "protocol": { "guid": "protocol-guid-1", "name": "Protocolo Estándar" },
    "comments": "Programa de primavera",
    "cancelled_at": null,
    "editable": true,
    "targets": [
      {
        "guid": "target-guid-1",
        "target_date": "2026-08-20",
        "animals": [{ "guid": "animal-guid-1", "rp": "RP-4521", "name": null }],
        "tasks": [
          {
            "protocol_task_guid": "task-guid-1",
            "description": "Aplicación día 1",
            "occurs_on": "2026-08-21",
            "occurs_at": "09:00",
            "important": true,
            "alerts": [
              {
                "protocol_task_alert_guid": "alert-guid-1",
                "occurs_on": "2026-08-20",
                "occurs_at": "08:00",
                "roles": ["vet-owner"],
                "message": "Recordatorio: aplicar tratamiento mañana",
                "require_confirmation": false,
                "recipients": [{ "guid": "profile-guid-1", "name": "Ana Gomez", "role": "vet-owner" }]
              }
            ]
          }
        ]
      }
    ],
    "managers": [{ "guid": "profile-guid-1", "name": "Ana Gomez", "role": "vet-owner", "origin": "vet" }],
    "created_at": "...",
    "updated_at": "..."
  }
}
```

**Response 404** — programa inexistente o de otra vet (el lookup filtra por `vet_id`, así que un guid de otro tenant también da 404, no 403).

### 4.4 Editar programa

`PUT /v1/vets/{vet}/programs/{guid}`

Requiere `programs.update`. Mismo body que crear, con la diferencia de que cada `targets[]` puede traer `guid` (edita ese target existente) o no traerlo (target nuevo). Un target existente cuyo `guid` no aparece en el payload se **borra** (cascade borra también sus animales asociados al target — no el `Animal` en sí).

Errores propios de negocio:

- `422 { "errors": { "reason": "not_editable" } }` si el programa está cancelado.
- `422 { "errors": { "reason": "must_have_one_target" } }` si el resultado final dejaría el programa sin targets.

### 4.5 Cancelar programa

`POST /v1/vets/{vet}/programs/{guid}/cancel`

Requiere `programs.update`. Sin body. Marca `cancelled_at`, no borra nada (trazabilidad). Devuelve `422 { "errors": { "reason": "not_editable" } }` si ya estaba cancelado.

---

## 5. Flujo recomendado para la pantalla mobile de "crear programa"

Pensado para agilidad en un dispositivo móvil — minimizar idas y vueltas y requests redundantes:

1. `GET /v1/user/vets` una sola vez al iniciar sesión → guardar `vet.guid` en el estado de la app, no volver a pedirlo en cada pantalla.
2. Cliente: `GET /v1/vets/{vet}/clients?search=` con debounce mientras el usuario tipea. Guardar `client.guid`.
3. En paralelo, apenas se elige el cliente, disparar **juntos**: `GET .../clients/{client}/establishments`, `GET .../clients/{client}/staff` (para el picker de responsables) y `GET .../protocols` (no depende del cliente, se puede precargar desde antes incluso).
4. Establecimiento y protocolo: selects simples sobre las listas ya traídas, sin nuevo request.
5. Grupos target: por cada target, autocomplete de `GET .../clients/{client}/animals?search=<rp>` con debounce; si no hay match, permitir tipear el RP igual (se crea al guardar). No hace falta resolver el animal contra el backend antes de armar el payload — el backend ya maneja "existe" vs "nuevo" en el mismo `store`.
6. Responsables: combinar el resultado de `GET .../staff` (vet) + `GET .../clients/{client}/staff` (cliente) en un solo picker, filtrando `blocked_at !== null`, taggeando `origin` en el cliente.
7. `POST /v1/vets/{vet}/programs` con todo junto. Si vuelve 422, mapear `errors.<campo>` directo a los mismos campos del formulario — los nombres de error coinciden 1:1 con las claves del payload (`client_id`, `establishment_id`, `protocol_id`, `manager_profile_ids.<índice>`, etc.).
8. Tras crear, si la UI quiere mostrar "qué va a pasar", pedir el detalle (`GET .../programs/{guid}`) para tener la proyección de tareas/alertas — no viene en la respuesta de `store`.
