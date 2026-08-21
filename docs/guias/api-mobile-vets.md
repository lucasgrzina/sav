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

## 3.6 Alta de un cliente nuevo asociado a la vet — circuito completo

Cubre el caso en que el cliente **no existe todavía** (ni global ni vinculado a esta vet) y hay que crearlo de cero, con su documentación fiscal por país, sus contactos y — opcionalmente — su primer owner y establecimiento. Verificado contra `back/app/Services/ClientService.php`, `back/app/Http/Requests/Clients/StoreClientRequest.php`, `back/database/seeders/CountrySeeder.php` y `back/routes/api/{clients,countries}.php`.

### 3.6.1 Paso previo obligatorio: buscar si ya existe (evitar duplicados)

Antes de mostrar el formulario de alta, la app **tiene que** intentar el lookup por `tax_id`, porque un mismo cliente (CUIT/CUIL/RUT) puede ya existir vinculado a otra vet o sin vincular a ninguna:

`GET /v1/vets/{vet}/clients/lookup?tax_id=30-12345678-9`

Requiere `clients.read`.

```json
// found: false → no existe, hay que crearlo (ir a 3.6.3)
{ "success": true, "data": { "found": false, "client": null } }
```

```json
// found: true, already_linked: false → existe pero no vinculado a esta vet, solo hay que linkear (3.6.2)
{
  "success": true,
  "data": {
    "found": true,
    "already_linked": false,
    "client": {
      "guid": "client-guid-9",
      "name": "Estancia La Loma",
      "tax_id": "30-12345678-9",
      "country": { "guid": "country-guid-ar", "name": "Argentina", "iso_code": "AR", "phone_prefix": "54" },
      "document_type": { "guid": "doctype-guid-cuit", "name": "CUIT" }
    }
  }
}
```

```json
// found: true, already_linked: true → ya es cliente de esta vet, no hacer nada más
{ "success": true, "data": { "found": true, "already_linked": true, "client": { "...": "..." } } }
```

### 3.6.2 Si ya existe sin vincular: link directo (sin crear de nuevo)

`POST /v1/vets/{vet}/clients/{guid}/link`

Requiere `clients.create`. Sin body — el `guid` va en la URL. `{guid}` es el guid del client resuelto en el lookup, **no** el body del alta.

**Response 201** — mismo shape que "Detalle de programa"-style resource, con `country`/`documentType` cargados (ver 3.6.4).

**Response 422** si ya estaba vinculado: `{ "success": false, "message": "Este cliente ya está vinculado a esta veterinaria." }`

Con esto termina el flujo para un cliente preexistente. El resto (3.6.3 en adelante) es solo para cliente 100% nuevo.

### 3.6.3 Países y tipos de documento (para armar el formulario)

No hay un tipo de documento único: cada país tiene los suyos, con su propia regex de validación aplicada server-side. Hoy la seed trae dos países:

`GET /v1/countries`

Requiere sesión (`auth:sanctum`, sin permiso de tenant — no va bajo `/vets/{vet}`).

```json
{
  "success": true,
  "data": [
    { "guid": "country-guid-ar", "name": "Argentina", "iso_code": "AR", "phone_prefix": "54" },
    { "guid": "country-guid-uy", "name": "Uruguay", "iso_code": "UY", "phone_prefix": "598" }
  ]
}
```

`GET /v1/countries/{guid}/document-types` — tipos de documento habilitados para el país elegido:

```json
// Argentina (country-guid-ar)
{
  "success": true,
  "data": [
    { "guid": "doctype-guid-cuit", "name": "CUIT", "country": { "guid": "country-guid-ar", "name": "Argentina", "iso_code": "AR", "phone_prefix": "54" } },
    { "guid": "doctype-guid-cuil", "name": "CUIL", "country": { "guid": "country-guid-ar", "name": "Argentina", "iso_code": "AR", "phone_prefix": "54" } }
  ]
}
```

```json
// Uruguay (country-guid-uy)
{
  "success": true,
  "data": [
    { "guid": "doctype-guid-rut", "name": "RUT", "country": { "guid": "country-guid-uy", "name": "Uruguay", "iso_code": "UY", "phone_prefix": "598" } }
  ]
}
```

Regex de validación por tipo (server-side, en `StoreClientRequest::taxIdRule()` contra `document_types.validation_regex` — la app puede replicarla en el frontend para dar feedback inmediato, pero el backend es la fuente de verdad):

| País | Tipo de documento | Regex | Ejemplo válido |
|---|---|---|---|
| Argentina | CUIT | `^\d{2}-\d{8}-\d{1}$` | `30-12345678-9` |
| Argentina | CUIL | `^\d{2}-\d{8}-\d{1}$` | `20-12345678-3` |
| Uruguay | RUT | `^\d{12}$` | `123456789012` |

Si el `document_type_guid` no tiene `validation_regex` seteada (no aplica hoy, pero el backend lo tolera), no se valida formato y cualquier string pasa. La app no tiene que hardcodear estas reglas como si fueran fijas para siempre — el catálogo puede crecer; siempre pedir `document-types` del país elegido en runtime.

### 3.6.4 Crear el cliente (con contactos iniciales opcionales)

`POST /v1/vets/{vet}/clients`

Requiere `clients.create`. Este único endpoint crea el `Client` **y** lo vincula a la vet actual en la misma transacción (`ClientService::create`, ver `back/app/Services/ClientService.php:27`) — no hace falta llamar a `/link` después de este `store`.

**Request**

```json
{
  "name": "Estancia La Loma",
  "country_guid": "country-guid-ar",
  "document_type_guid": "doctype-guid-cuit",
  "tax_id": "30-12345678-9",
  "address": "Ruta 5 km 120",
  "city": "Pergamino",
  "state": "Buenos Aires",
  "zip_code": "2700",
  "contacts": [
    { "type": "whatsapp", "value": "+5491112345678", "label": "Celular", "is_primary": true, "use_for_alerts": true },
    { "type": "email", "value": "contacto@estancialaloma.com", "label": "Administración", "is_primary": true, "use_for_alerts": false },
    { "type": "phone", "value": "+542477123456", "label": "Fijo", "is_primary": false, "use_for_alerts": false }
  ]
}
```

Reglas de negocio (`StoreClientRequest`):

- `name`: requerido, máx 150.
- `country_guid`: requerido, tiene que existir en `countries`.
- `document_type_guid`: requerido, tiene que existir en `document_types` — la app tiene que haber pedido el país **antes** para acotar el picker de tipos, pero el backend no valida que el tipo pertenezca a ese país específico, así que es responsabilidad de la UI mandarlos coherentes.
- `tax_id`: requerido, máx 50, validado contra la regex del `document_type_guid` elegido (tabla de 3.6.3).
- `address` / `city` / `state` / `zip_code`: todos opcionales.
- `contacts`: array opcional, **máximo 10** ítems.
  - `contacts[].type`: uno de `email`, `phone`, `whatsapp` (enum `App\Enums\ContactType`, ver 3.6.5).
  - `contacts[].value`: requerido, máx 200. Si `type` es `phone` o `whatsapp`, tiene que matchear formato E.164 (`^\+?[1-9]\d{7,14}$`, ej. `+5491112345678`) — para `email` no hay validación de formato de email a nivel de este array (ojo, es una laxitud real del backend, no una omisión de la spec).
  - `contacts[].label`: opcional, texto libre (ej. "Celular", "Administración"), máx 100.
  - `contacts[].is_primary`: opcional, boolean. El primario se resuelve **por tipo** — puede haber un whatsapp primario y un email primario al mismo tiempo, son independientes.
  - `contacts[].use_for_alerts`: opcional, boolean — marca si ese contacto puede recibir alertas del protocolo (relevante para cuando este client después aparece como `origin: 'client'` en `manager_profile_ids`, ver §3.5).

**Response 201**

```json
{
  "success": true,
  "message": "Cliente creado correctamente.",
  "data": {
    "guid": "client-guid-1",
    "name": "Estancia La Loma",
    "tax_id": "30-12345678-9",
    "address": "Ruta 5 km 120",
    "city": "Pergamino",
    "state": "Buenos Aires",
    "zip_code": "2700",
    "country": { "guid": "country-guid-ar", "name": "Argentina", "iso_code": "AR", "phone_prefix": "54" },
    "document_type": { "guid": "doctype-guid-cuit", "name": "CUIT", "country": { "guid": "country-guid-ar", "name": "Argentina", "iso_code": "AR", "phone_prefix": "54" } },
    "contacts": [
      { "guid": "contact-guid-1", "type": "whatsapp", "label": "Celular", "value": "+5491112345678", "is_primary": true, "use_for_alerts": true, "created_at": "2026-08-21T12:00:00.000000Z" },
      { "guid": "contact-guid-2", "type": "email", "label": "Administración", "value": "contacto@estancialaloma.com", "is_primary": true, "use_for_alerts": false, "created_at": "2026-08-21T12:00:00.000000Z" },
      { "guid": "contact-guid-3", "type": "phone", "label": "Fijo", "value": "+542477123456", "is_primary": false, "use_for_alerts": false, "created_at": "2026-08-21T12:00:00.000000Z" }
    ],
    "created_at": "2026-08-21T12:00:00.000000Z"
  }
}
```

**Response 422** — errores mapean 1:1 a las claves del payload:

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "tax_id": ["El formato del CUIT es inválido."],
    "contacts.0.value": ["El número de teléfono debe estar en formato E.164 (ej: +5491112345678)."]
  }
}
```

### 3.6.5 Cómo se guarda: `Client`, `Contact` (polimórfico) y el enum de tipo

- `Client` (`back/app/Models/Client.php`) guarda `country_id` y `document_type_id` como FKs internas (no expone `id` numérico en el resource — usa `$hidden = ['id']`; todo lo que ve la app es `guid`). `country_guid`/`document_type_guid` del payload se resuelven a esos IDs dentro de `ClientService::resolveIds()` antes de persistir.
- El vínculo cliente↔vet vive en la tabla pivote `client_vet` (`Client::vets()` / `Vet::clients()`, `belongsToMany` con `withTimestamps()`) — un mismo `Client` puede estar vinculado a **varias** vets simultáneamente (multi-tenant real, por eso existe el flujo de lookup/link en vez de permitir duplicar el registro).
- Los contactos NO son una tabla `client_contacts` aparte: `Contact` (`back/app/Models/Contact.php`) es **polimórfico** (`contactable_type` + `contactable_id`, trait `HasContacts::contacts()` → `morphMany`). El mismo modelo `Contact` sirve para `Client`, `Vet` y `UserProfile` — por eso al crear un cliente los contactos se guardan a través de `ContactService::create()`, pasándole la instancia de `Client` recién creada como `$contactable`.
- `type` en `Contact` es el enum `App\Enums\ContactType: string` con exactamente 3 casos — no hay un cuarto tipo, ni SMS ni "otro":
  ```php
  enum ContactType: string {
      case Email    = 'email';
      case Phone    = 'phone';
      case Whatsapp = 'whatsapp';
  }
  ```
- `is_primary` se resuelve por **combinación (contactable, type)**: al crear/actualizar un contacto con `is_primary: true`, el backend descarta automáticamente el flag en cualquier otro contacto del mismo cliente y mismo `type` (`ContactService::create()` → `clearPrimaryForType()`). No hay que mandar manualmente "desmarcar" el contacto anterior.
- Si más adelante hay que editar los contactos de un cliente ya creado (no en el alta), existe el sub-recurso dedicado — no se reenvía todo el `Client` con `PUT`:
  - `GET /v1/vets/{vet}/clients/{client}/contacts` — listar (`clients.read`)
  - `POST /v1/vets/{vet}/clients/{client}/contacts` — agregar uno (`clients.update`)
  - `PUT /v1/vets/{vet}/clients/{client}/contacts/{guid}` — editar (`clients.update`)
  - `DELETE /v1/vets/{vet}/clients/{client}/contacts/{guid}` — borrar (`clients.update`)

  También existe `PUT /v1/vets/{vet}/clients/{guid}` para editar el cliente completo (nombre, país, doc, dirección) — si el body trae `contacts`, hace un **diff completo** (`ContactService::syncContacts`): contactos con `guid` conocido se actualizan, sin `guid` o con `guid` ajeno se crean, y los que existían pero no vinieron en el array **se borran**. Para el alta inicial (3.6.4) no aplica este diff — es creación simple de la lista que se manda.

### 3.6.6 Opcional: primer owner del cliente

Un cliente recién creado no tiene ningún usuario con acceso propio hasta que se le crea un owner. Si el flujo de alta de la app también cubre esto:

`POST /v1/vets/{vet}/clients/{guid}/owners`

Requiere `clients.owners.create`. Si el email no corresponde a ningún `User` existente, el backend lo crea y encola un mail de invitación (`ClientOwnerInvitationEmail` vía `SendClientOwnerInvitationJob`) con link de verificación con expiración configurable (`auth.invitation_link_expiration_hours`, default 72h).

**Request**

```json
{ "email": "carlos.diaz@example.com", "first_name": "Carlos", "last_name": "Diaz" }
```

**Response 201**

```json
{
  "success": true,
  "message": "Owner creado correctamente.",
  "data": {
    "guid": "profile-guid-2",
    "user": { "guid": "user-guid-2", "name": "Carlos Diaz", "first_name": "Carlos", "last_name": "Diaz", "email": "carlos.diaz@example.com" },
    "role": { "guid": "role-guid-3", "name": "client-owner" }
  }
}
```

### 3.6.7 Opcional: primer establecimiento del cliente

Si la app da de alta también el establecimiento en el mismo flujo (en vez de dejarlo para después):

`POST /v1/vets/{vet}/clients/{client}/establishments`

Requiere `establishments.create`.

```json
{
  "name": "Establecimiento Norte",
  "renspa": "01.234.5.67890/01",
  "address": "Camino Rural km 8",
  "city": "Pergamino",
  "state": "Buenos Aires",
  "zip_code": "2700",
  "latitude": -33.8833,
  "longitude": -60.5667
}
```

Todos los campos salvo `name` son opcionales. `latitude`/`longitude` validados en rango `[-90, 90]` / `[-180, 180]` si se envían.

### 3.6.8 Flujo recomendado end-to-end para "dar de alta cliente nuevo"

1. Usuario tipea el CUIT/CUIL/RUT → `GET /v1/vets/{vet}/clients/lookup?tax_id=` (3.6.1).
2. Si `found: true` → rama corta: `already_linked: true` no hace nada más; `already_linked: false` dispara `POST /{guid}/link` (3.6.2) y termina.
3. Si `found: false` → mostrar formulario de alta:
   a. `GET /v1/countries` una vez, cachear en el estado de la app (no cambia por sesión).
   b. Al elegir país → `GET /v1/countries/{guid}/document-types` para poblar el picker de tipo de documento (CUIT/CUIL para AR, RUT para UY).
   c. Completar `tax_id` con feedback de formato usando la regex de la tabla en 3.6.3 (opcional, cosmético — el backend re-valida en el submit).
   d. Contactos: agregar 0 a 10 filas `{type, value, label?, is_primary?, use_for_alerts?}`; si `type` es `phone`/`whatsapp` exigir formato E.164 en el input.
4. `POST /v1/vets/{vet}/clients` con todo junto (3.6.4). Si 422, mapear `errors.<campo>` — incluye índices de array para contactos (`contacts.0.value`).
5. Opcional, tras el 201: `POST /{guid}/owners` (3.6.6) y/o `POST /{client}/establishments` (3.6.7) si el flujo de la app los pide en la misma pantalla o en pantallas siguientes del wizard.

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
