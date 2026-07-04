# Análisis Funcional — SAV (Sistema de Alertas Veterinarias)

**Fecha de análisis:** 2026-06-20  
**Tipo de documento:** Relevamiento funcional completo  
**Sistema:** SAV Backend — rama `develop`  
**Clasificación:** Interno

---

## Mapa Funcional General

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                        SAV — Sistema de Alertas Veterinarias                     │
│                                                                                  │
│  ┌──────────────────────┐         ┌─────────────────────────────────────────┐   │
│  │   Panel SuperAdmin   │         │          Panel Vet (/vet/{slug})         │   │
│  │   (/superadmin)      │         │                                          │   │
│  │                      │         │  Reproducción  │  Salud  │  Agenda       │   │
│  │  • Gestión de Vets   │         │  • Programas   │ • Planes│  • Eventos    │   │
│  │  • Técnicas         │         │  • Protocolos  │Sanitarios│              │   │
│  │  • Actividades       │         │                │         │  Gestión      │   │
│  │  • Plantillas        │         │  Clientes      │ Config  │  • Usuarios   │   │
│  │  • Soporte           │         │  • Clientes    │ • Config│  • Facturas   │   │
│  │  • Tutoriales        │         │  • Importación │ • Sopor.│  • Importar   │   │
│  └──────────────────────┘         └─────────────────────────────────────────┘   │
│                                                                                  │
│  ┌───────────────────────────────────────────────────────────────────────────┐  │
│  │                          API REST (/api/v1)                               │  │
│  │  Auth │ Técnicas │ Clientes │ Programas │ Planes Sanitarios │ Agenda      │  │
│  └───────────────────────────────────────────────────────────────────────────┘  │
│                                                                                  │
│  ┌───────────────────────────────────────────────────────────────────────────┐  │
│  │                    Núcleo de Notificaciones                               │  │
│  │  Scheduler → SendAlertsJob → TwilioChannel → SMS / WhatsApp              │  │
│  └───────────────────────────────────────────────────────────────────────────┘  │
│                                                                                  │
│  ┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐              │
│  │   Twilio (SMS)  │   │  WhatsApp Cloud │   │  Email (SMTP)   │              │
│  └─────────────────┘   └─────────────────┘   └─────────────────┘              │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### Actores del sistema

| Actor | Rol | Acceso |
|---|---|---|
| **SuperAdmin** | Administrador global de la plataforma | Panel `/superadmin` |
| **Vet** | Veterinario propietario de la clínica/empresa | Panel `/vet/{slug}` + API |
| **Vet Assistant** | Asistente del veterinario | Panel `/vet/{slug}` + API |
| **Vet Administrative** | Administrativo del veterinario | Panel `/vet/{slug}` + API |
| **Client Owner** | Dueño del establecimiento | API (app móvil) |
| **Client Manager** | Encargado del establecimiento | API (app móvil) |
| **Client Administrative** | Administrativo del cliente | API (app móvil) |

### Módulos del sistema

| # | Módulo | Descripción breve |
|---|---|---|
| 1 | [Autenticación y Usuarios](#1-autenticación-y-usuarios) | Login, roles, permisos, cambio de contraseña |
| 2 | [Gestión de Veterinarios](#2-gestión-de-veterinarios) | Alta y administración de tenants Vet |
| 3 | [Gestión de Clientes](#3-gestión-de-clientes) | Clientes, establecimientos, grupos y animales |
| 4 | [Técnicas y Protocolos](#4-técnicas-y-protocolos) | Catálogo de técnicas reproductivas y protocolos |
| 5 | [Programas Reproductivos](#5-programas-reproductivos) | Instancias de protocolo aplicadas en campo |
| 6 | [Planes Sanitarios](#6-planes-sanitarios) | Gestión anual de actividades sanitarias |
| 7 | [Sistema de Alertas](#7-sistema-de-alertas) | Motor de notificaciones polimórficas |
| 8 | [Agenda y Eventos](#8-agenda-y-eventos) | Calendario de eventos del veterinario |
| 9 | [Importación de Clientes](#9-importación-de-clientes) | Carga masiva de clientes desde Excel |
| 10 | [Facturación](#10-facturación) | Registro de facturas e items |
| 11 | [WhatsApp y Opt-Out](#11-whatsapp-y-opt-out) | Webhooks entrantes y manejo de bajas |
| 12 | [Soporte y Tutoriales](#12-soporte-y-tutoriales) | Mensajes de soporte y material de ayuda |
| 13 | [Configuración del Panel Vet](#13-configuración-del-panel-vet) | Personalización visual por veterinario |

---

## 1. Autenticación y Usuarios

### Objetivo
Gestionar el ciclo de vida de la identidad de los usuarios: ingreso al sistema, cambio de contraseña y cierre de sesión, tanto para la app móvil (API) como para los paneles web (Filament).

### Usuarios involucrados
Todos los roles del sistema.

### Flujo funcional

#### 1.1 Login (API)
```
Usuario → POST /api/v1/login
         { username, password, device_name }
              │
              ▼
    AuthController@login
              │
    Busca User por username (único)
              │
    ⚠️ [BUG CRÍTICO]: Verificación de password COMENTADA
    → Retorna token aunque el password sea incorrecto
              │
    Crea Sanctum token para device_name
              │
    ◄─── { token, user: { name, email, role, vet, client, ... } }
```

#### 1.2 Login (Panel Web — Filament)
```
Vet → GET /vet/{slug}/login → CustomLogin (Form: email + password)
       ▼
Filament autentica con guard 'web'
       ▼
Middleware RedirectIfNotVet → verifica user.vet_id != null
       ▼
Middleware PasswordUpdatedMiddleware → si password_changed_at == null
       → Redirige a edit-profile con notificación
       ▼
Panel vet disponible bajo /vet/{slug}/*
```

#### 1.3 Cambio de contraseña
```
POST /api/v1/change-password
{ current_password, password, password_confirmation }
       ▼
Verifica Hash::check(current_password, user.password)
       ▼
Actualiza user.password (bcrypt)
Registra user.password_changed_at = now()
       ▼
◄─── { message: "Contraseña actualizada" }
```

#### 1.4 Logout
```
POST /api/v1/logout
       ▼
$user->tokens()->delete() → elimina TODOS los tokens del usuario
       ▼
◄─── 200 OK
```

### Datos que administra

**Tabla `users`**

| Campo | Tipo | Regla |
|---|---|---|
| `name` | string | Nombre completo |
| `username` | string (unique) | Usado como identificador de login |
| `email` | string nullable | Para recuperación de contraseña |
| `phone` | string nullable | Teléfono formateado (54XXXXXXXXXX) |
| `password` | string (bcrypt) | — |
| `password_changed_at` | timestamp nullable | null = obligar cambio al primer login |
| `vet_id` | FK nullable | Pertenece a un Vet (null = superadmin/cliente) |
| `client_id` | FK nullable | Pertenece a un Client (null = superadmin/vet) |
| Soft deletes | — | Usuarios eliminados son restaurables |

**Tabla `personal_access_tokens`** (Sanctum)

| Campo | Descripción |
|---|---|
| `name` | device_name enviado en el login |
| `token` | Hash del token |
| `tokenable_*` | Polimórfico hacia `users` |

### Reglas de negocio

1. El `username` es el identificador único de login (no el email).
2. El `device_name` permite múltiples tokens simultáneos (multi-dispositivo).
3. El logout elimina **todos** los tokens del usuario, no solo el del dispositivo actual.
4. `password_changed_at = null` obliga al usuario a cambiar su contraseña antes de operar en Filament.
5. La determinación de panel accesible se infiere desde los campos FK:
   - `vet_id = null` Y `client_id = null` → SuperAdmin
   - `vet_id != null` Y `client_id = null` → Panel Vet
   - `client_id != null` → Solo API (app móvil)

### Validaciones

- Login: `username` required, `password` required, `device_name` required.
- Cambio de contraseña: `current_password` must match stored hash; `password` confirmed.

### Casos especiales

- Si el username no existe, se devuelve `422 Unprocessable Entity` con mensaje `Credenciales inválidas`.
- Un usuario de tipo cliente no puede acceder a ningún panel Filament.

### Dependencias

- Spatie Laravel Permission (roles y permisos)
- Laravel Sanctum (tokens API)
- Middleware: `PasswordUpdatedMiddleware`, `RedirectIfNotVet`, `RedirectIfNotSuperadmin`

---

## 2. Gestión de Veterinarios

### Objetivo
Registrar y administrar los veterinarios que operan como tenants del sistema. Un Vet representa a una clínica, empresa o profesional que tiene su propio panel, usuarios y clientes.

### Usuarios involucrados
- **SuperAdmin**: Gestiona todos los veterinarios desde el panel `/superadmin`.
- **Vet**: Se registra públicamente o es dado de alta por SuperAdmin.

### Flujo funcional

#### 2.1 Registro público de veterinario
```
Visitante → GET /register → Formulario público con reCAPTCHA v3
                ▼
           POST /register
           { name, cuit, address, phone, registration_number,
             college, university, vet_id, email, password }
                ▼
         RegisterController@register
                ▼
         Valida reCAPTCHA (score >= 0.5)
         Crea Vet (slug auto-generado desde name)
         Crea User con role 'vet'
         Envía email de verificación
                ▼
         Redirige a /vet/{slug}
```

#### 2.2 Alta desde SuperAdmin
```
SuperAdmin → /superadmin/vets/create
                ▼
         VetResource (Form: name, cuit, address, phone, etc.)
                ▼
         Crea Vet → auto-genera slug
         Crea usuario administrador del vet
         Asigna rol 'vet'
```

#### 2.3 Gestión de administradores de vet
```
/superadmin/vets/{id}/manage-admins
                ▼
         ManageVetAdmins Page
         Lista usuarios del vet (rol vet)
         Permite crear/eliminar usuarios administradores
```

### Datos que administra

**Tabla `vets`**

| Campo | Descripción |
|---|---|
| `name` | Nombre de la clínica/empresa |
| `cuit` | CUIT fiscal del veterinario |
| `address` | Dirección |
| `phone` | Teléfono |
| `registration_number` | Matrícula profesional |
| `college` | Colegio veterinario |
| `university` | Universidad |
| `vet_id` | DNI del profesional |
| `slug` | Identificador URL (generado automáticamente desde `name`) |

### Reglas de negocio

1. El `slug` del Vet es inmutable una vez generado (identificador de URL del panel).
2. Cada Vet tiene al menos un usuario con rol `vet`.
3. El Vet actúa como **tenant**: toda la información asociada (clientes, programas, protocolos) queda aislada por `vet_id`.
4. Un cliente puede pertenecer a múltiples Vets (relación N:M via `client_vet`).

### Validaciones

- CUIT: único en el sistema.
- Email del usuario principal: único en el sistema.
- reCAPTCHA v3 requerido en registro público (score mínimo configurable).

### Dependencias

- Módulo de Autenticación (crea usuario vinculado)
- Spatie Permission (asigna rol `vet`)
- Filament Tenancy (Vet como modelo de tenant)
- `spatie/laravel-sluggable` (generación automática del slug)

---

## 3. Gestión de Clientes

### Objetivo
Administrar los clientes del veterinario (ganaderos/productores), sus establecimientos, grupos de animales y usuarios asociados.

### Usuarios involucrados
- **Vet / Vet Assistant / Vet Administrative**: CRUD completo desde panel.
- **Todos los roles de cliente**: Solo lectura vía API.

### Flujo funcional

#### 3.1 Creación manual de cliente (Panel)
```
Vet → /vet/{slug}/clients/create
           ▼
      ClientResource (Form)
      Campos: name, cuit_cuil, address, city, postal_code,
              state, country, phone_1, phone_2, email
           ▼
      Guarda Client vinculado al vet actual
      RelationManagers disponibles:
        → Establishments (establecimientos del cliente)
        → Users (usuarios del cliente con roles)
        → Animals Groups (lotes de animales)
        → HealthPlans (planes sanitarios)
```

#### 3.2 Estructura jerárquica del cliente
```
Client
 └── Establishment (campos/establecimientos)
      └── AnimalsGroup (lote/grupo de animales)
           └── Animal (animal individual — campo rp_donor)
```

#### 3.3 Consulta desde la API
```
GET /api/v1/clients
       ▼
ClientsController@clients
       ▼
Retorna: clients con establishments y users del vet autenticado
         (sin GlobalScope de vet, usa vet_id del usuario)

GET /api/v1/clients/{id}/establishments
       → Lista establecimientos del cliente

GET /api/v1/clients/{id}/managers
       → Lista usuarios del cliente (roles client-*)

GET /api/v1/clients/{establishment}/groups
       → Lista grupos de animales con animales incluidos
```

### Datos que administra

**Tabla `clients`**

| Campo | Descripción |
|---|---|
| `name` | Razón social o nombre del cliente |
| `cuit_cuil` | CUIT/CUIL (único) |
| `address`, `city`, `postal_code`, `state`, `country` | Datos de domicilio |
| `phone_1`, `phone_2` | Teléfonos de contacto |
| `email` | Correo del cliente |
| Soft deletes | Permite restaurar clientes eliminados |

**Tabla `establishments`**

| Campo | Descripción |
|---|---|
| `name` | Nombre del establecimiento |
| `city` | Ciudad |
| `postal_code` | CP |
| `latitude` | Latitud GPS |
| `length` | ⚠️ Naming bug: es `longitude` pero se llama `length` |
| `client_id` | FK al cliente |

**Tabla `animals_groups`**

| Campo | Descripción |
|---|---|
| `name` | Nombre del lote |
| `establishment_id` | FK al establecimiento |

**Tabla `animals`**

| Campo | Descripción |
|---|---|
| `rp_donor` | Identificador del animal (RP donante) |
| `animals_group_id` | FK al grupo |

### Reglas de negocio

1. Un cliente puede pertenecer a múltiples veterinarios (relación N:M via `client_vet`).
2. El `cuit_cuil` es único en todo el sistema (independiente del vet).
3. En el panel Filament, los clientes están filtra dos automáticamente por el vet activo (GlobalScope en `Client::booted`). En la API, el filtro es manual por `vet_id` del usuario.
4. Los clientes eliminados (soft delete) pueden ser restaurados desde la importación Excel.
5. Los usuarios de tipo cliente tienen acceso únicamente a la app móvil (API), no al panel Filament.

### Validaciones

- Panel: `cuit_cuil` requerido, name requerido.
- API: sin validaciones explícitas en endpoints de solo lectura.

### Casos especiales

- Un mismo cliente puede estar vinculado a más de un vet sin duplicar sus datos maestros.
- Si un cliente eliminado (soft delete) es importado nuevamente, se restaura (`restore()`).

### Dependencias

- Módulo de Autenticación (usuarios de tipo cliente)
- Módulo de Programas (referencia `client_id`, `establishment_id`)
- Módulo de Planes Sanitarios (referencia `client_id`, `establishment_id`)
- Módulo de Importación (crea clientes en masa)

---

## 4. Técnicas y Protocolos

### Objetivo
Gestionar el catálogo de técnicas reproductivas (IATF, inseminación, etc.) y los protocolos hormonales asociados a cada técnica, incluyendo sus tareas y alertas programadas.

### Usuarios involucrados
- **SuperAdmin**: Gestiona técnicas globales desde `/superadmin`.
- **Vet / Vet Assistant**: Gestiona sus propios protocolos desde panel y los consume vía API.

### Flujo funcional

#### 4.1 Catálogo de Técnicas (SuperAdmin)
```
SuperAdmin → /superadmin/techniques
                  ▼
          TechniqueResource
          Crea/edita técnicas con jerarquía padre-hijo
          Ejemplo:
            Inseminación Artificial (parent)
             └── IA Bovina (child, technique_id = parent.id)
             └── IA Equina (child)
          
          Cada técnica tiene:
          - name: Nombre de la técnica
          - target_date_name: Nombre del campo fecha objetivo
            (ej: "Fecha de Parto", "Fecha IATF")
          - type: Clasificación
          - protocols_name: Nombre que verán los usuarios
```

#### 4.2 Gestión de Protocolos (Panel Vet)
```
Vet → /vet/{slug}/protocols/create
           ▼
      ProtocolResource
      Campos: name, technique_id, color (para UI), vet_id (auto)
           ▼
      ProtocolTasksRelationManager
        → Crea tareas del protocolo (ProtocolTask):
          { days_offset, time_of_day (Before/After), description, time, important }
           ▼
      ProtocolAlertsRelationManager
        → Crea alertas del protocolo (ProtocolAlert):
          { days_offset, time_of_day, time, text (JSON), roles (JSON),
            require_confirmation }
```

#### 4.3 Importación masiva de tareas
```
Vet → Sube archivo Excel con tareas
           ▼
      ProtocolTasksImport
      Formato de columnas:
        [0] days_offset
        [1] time_of_day (Antes / After)
        [2] description
        [3] time (HH:MM)
        [4] important (SI / NO)
```

#### 4.4 Replicación de protocolo
```
Vet → Botón "Replicar" en ProtocolResource
           ▼
      ReplicateProtocol action
           ▼
      Crea copia del protocolo con todas sus tareas y alertas
```

#### 4.5 Consulta desde API
```
GET /api/v1/techniques?type={type}
       → Técnicas con hijos (jerarquía)

GET /api/v1/techniques/protocols
       → Protocolos del vet autenticado + protocolos globales (vet_id = null)

GET /api/v1/techniques/{id}/protocols
       → Protocolos de una técnica específica
```

### Datos que administra

**Tabla `techniques`**

| Campo | Descripción |
|---|---|
| `name` | Nombre de la técnica |
| `target_date_name` | Etiqueta para la fecha objetivo en programas |
| `type` | Tipo (ej: "technique") |
| `parent_id` | FK self-referencial (jerarquía) |
| `protocols_name` | Nombre visible para los protocolos de esta técnica |

**Tabla `protocols`**

| Campo | Descripción |
|---|---|
| `name` | Nombre del protocolo |
| `color` | Color HEX para la UI |
| `technique_id` | FK a la técnica |
| `vet_id` | FK al vet (null = protocolo global/superadmin) |
| Soft deletes | — |

**Tabla `protocols_tasks`**

| Campo | Descripción |
|---|---|
| `protocol_id` | FK al protocolo |
| `description` | Descripción de la tarea |
| `days_offset` | Días de offset respecto a `target_date` |
| `time_of_day` | 'Before' o 'After' la fecha objetivo |
| `time` | Hora de ejecución (HH:MM) |
| `important` | Flag de importancia (boolean) |

**Tabla `protocol_alerts`**

| Campo | Descripción |
|---|---|
| `protocol_id` | FK al protocolo |
| `text` | JSON con contenido del mensaje |
| `days_offset` | Días de offset respecto a `target_date` |
| `time_of_day` | 'Before' o 'After' |
| `time` | Hora de envío |
| `roles` | JSON con roles que recibirán la alerta |
| `require_confirmation` | Boolean (si necesita confirmación del usuario) |
| `confirmed_at` | Timestamp de confirmación |

### Reglas de negocio

1. Los protocolos con `vet_id = null` son **globales** (creados por SuperAdmin) y visibles por todos los vets.
2. Los protocolos con `vet_id != null` solo son visibles por ese vet específico.
3. `days_offset` con `time_of_day = 'Before'` resta días a `target_date`; `'After'` suma días.
4. Las alertas del protocolo **no se envían directamente**; son plantillas que se instancian al crear un programa.
5. El campo `roles` en `ProtocolAlert` actúa como filtro: si está definido, solo los usuarios con esos roles reciben la alerta. Si está vacío, la reciben los managers del programa.

### Validaciones

- Protocolo: `name`, `technique_id` requeridos.
- ProtocolTask/Alert: `days_offset` requerido, `time_of_day` en ('Before', 'After').
- Importación: `time_of_day` acepta 'Antes' o 'After' (normalizado).

### Casos especiales

- La replicación incluye todas las tareas y alertas del protocolo original.
- Al eliminar un protocolo, las instancias (Programas) que lo referencian quedan huérfanas (no hay ON DELETE CASCADE explícito en los programas).

### Dependencias

- Módulo de Programas (consume protocolos y sus alertas como plantillas)
- Módulo de Sistema de Alertas (instancia las `ProtocolAlert` en `Alert` concretas)

---

## 5. Programas Reproductivos

### Objetivo
Gestionar las instancias concretas de un protocolo reproductivo aplicado a un grupo de animales en un establecimiento, incluyendo la generación automática de alertas y el seguimiento del estado del programa.

### Usuarios involucrados
- **Vet / Vet Assistant**: Crean y gestionan programas.
- **Vet / Client roles**: Reciben alertas como managers.
- **Client Manager / Owner**: Consultan programas y confirman alertas vía API.

### Flujo funcional

#### 5.1 Creación de programa
```
Vet → POST /api/v1/programs
           { client_id, establishment_id, technique_id, protocol_id,
             target_date, state, comments, managers: [user_ids],
             group_name?, animals_rp? }
                 ▼
         ProgramsController@store
                 ▼
         ┌── CreateProgramAction ──────────────────────┐
         │  1. Crea Group (si group_name != null)       │
         │  2. Si animals_rp:                           │
         │     - Parsea lista de RPs (salto de línea)   │
         │     - Crea Animal por cada RP                │
         │     - Los asocia al Group                    │
         │  3. Crea Program                             │
         │     { vet_id=auth.vet.id, client_id,         │
         │       establishment_id, technique_id,        │
         │       protocol_id, group_id, target_date,    │
         │       state, comments }                      │
         │  4. Adjunta managers (program_manager pivot)  │
         └────────────────────────────────────────────┘
                 ▼
         ┌── UpsertProgramAlertsAction ────────────────┐
         │  Para cada ProtocolAlert del protocolo:      │
         │  1. Calcula send_at:                         │
         │     - Before: target_date - days_offset días │
         │     - After:  target_date + days_offset días │
         │     - Ajusta hora con ->setTime(H, M)        │
         │  2. Si send_at < HOY → skip (no crea alerta) │
         │  3. Determina recipients:                    │
         │     - Si roles != null: filtra managers por rol│
         │     - Si roles == null: todos los managers   │
         │  4. Crea Alert                               │
         │     { model: Program, notification_class,    │
         │       text (JSON), send_at,                  │
         │       require_confirmation }                  │
         │  5. Adjunta recipients (alert_user pivot)    │
         └────────────────────────────────────────────┘
                 ▼
         Notifica a managers:
         ProgramCreatedNotification → Twilio/WhatsApp
                 ▼
         ◄── { program con alertas agrupadas por día }
```

#### 5.2 Estado del programa (calculado, no almacenado)
```
El campo 'state' en la tabla almacena el valor ingresado.
El estado efectivo se calcula en el show de API:

program.state = 'cancelled'        → Cancelado
Sin alertas delivered_at           → Pendiente
Todas las alertas con delivered_at  → Completado
Algunas con delivered_at            → En progreso
```

#### 5.3 Detalle del programa (API)
```
GET /api/v1/programs/{id}
           ▼
ProgramsController@show
           ▼
Retorna JSON con "days": [
  {
    date: "YYYY-MM-DD",
    header_day: "Día -5 / Día 0 / Día +3...",
    alerts: [
      {
        id, send_at, text (JSON), require_confirmation,
        confirmed_at, delivered_at,
        visible: bool (según rol del usuario),
        completed: bool
      }
    ]
  }
]

Visibilidad de alertas por rol:
  - vet / vet-assistant → ven TODAS las alertas
  - otros roles → solo ven sus alertas (recipient)
```

#### 5.4 Confirmación de alerta
```
POST /api/v1/tasks/{alert_id}/complete
           ▼
ProgramsController@confirmAlert
           ▼
Alert::find(alert_id)
alert.confirmed_at = now()
alert.save()
           ▼
◄── { alert actualizada }
```

#### 5.5 Cancelación de programa
```
POST /api/v1/programs/{id}/cancel
           ▼
ProgramsController@cancel
           ▼
CancelProgramAction:
  1. program.state = 'cancelled'
  2. Elimina alertas no enviadas (delivered_at = null)
  3. Crea alerta de cancelación:
     { notification_class: ProgramCanceledNotification,
       send_at: now(),
       recipients: todos los managers }
           ▼
◄── { program actualizado }
```

#### 5.6 Actualización de programa
```
PUT /api/v1/programs/{id}
           ▼
UpdateProgramAction:
  1. Actualiza campos del programa
  2. Elimina alertas no enviadas (delivered_at = null)
  3. Ejecuta UpsertProgramAlertsAction (recalcula alertas)
```

#### 5.7 Generación de PDF
```
GET /programs/{id}/get_file
           ▼
GetFileController@getFile
           ▼
DomPDF renderiza vista Blade del programa
Guarda en disk 'projects'
Retorna descarga del archivo
```

### Datos que administra

**Tabla `programs`**

| Campo | Descripción |
|---|---|
| `vet_id` | FK al veterinario |
| `client_id` | FK al cliente |
| `establishment_id` | FK al establecimiento |
| `group_id` | FK al grupo de animales (nullable) |
| `technique_id` | FK a la técnica |
| `protocol_id` | FK al protocolo |
| `target_date` | Fecha objetivo del programa |
| `state` | Estado ('Pending', 'Completed', 'cancelled') |
| `comments` | Comentarios adicionales |

**Tabla `program_manager`** (pivot)

| Campo | Descripción |
|---|---|
| `program_id` | FK al programa |
| `user_id` | FK al usuario manager |

### Reglas de negocio

1. Solo usuarios con `vet_id` pueden crear y actualizar programas.
2. Las alertas con `send_at` anterior al inicio del día actual no se crean al generar un programa.
3. Al actualizar un programa, las alertas ya enviadas (`delivered_at != null`) no se eliminan ni recrean.
4. Al cancelar un programa, se crea inmediatamente una alerta de cancelación con `send_at = now()`.
5. La visibilidad de alertas en el detalle del programa depende del rol del usuario autenticado.
6. El campo `editable` es `false` si el programa está cancelado o si la primera tarea ya fue entregada.
7. Los managers son quienes reciben las notificaciones de alertas; se asignan al crear el programa.
8. El campo `roles` en `ProtocolAlert` limita qué managers reciben cada alerta específica.

### Validaciones

```
client_id:        required, exists:clients,id
establishment_id: required, exists:establishments,id
technique_id:     required, exists:techniques,id
protocol_id:      required, exists:protocols,id
target_date:      required, date
state:            required, in:Pending,Completed
comments:         nullable, string
managers:         required (array de user IDs)
group_name:       sometimes, string
animals_rp:       sometimes, string (RPs separados por salto de línea)
```

### Casos especiales

- Si `group_name` no viene en el request, no se crea grupo de animales.
- Si `animals_rp` viene pero no `group_name`, los animales se crean sin grupo.
- Si todos los `send_at` calculados son anteriores a hoy, el programa se crea sin alertas.
- Método `addTaskToProgramFromProtocol` existe en el controlador pero **no está conectado a ninguna ruta** (código muerto).

### Dependencias

- Módulo de Técnicas y Protocolos (plantilla de alertas)
- Módulo de Sistema de Alertas (motor de envío)
- Módulo de Clientes (client_id, establishment_id)
- Módulo de Autenticación (managers, vet_id)
- Twilio / WhatsApp (notificaciones de creación y cancelación)

---

## 6. Planes Sanitarios

### Objetivo
Gestionar los planes anuales de actividades sanitarias (vacunaciones, desparasitaciones, controles) para los establecimientos de los clientes, generando alertas automáticas mensuales.

### Usuarios involucrados
- **Vet / Vet Assistant**: Crean y gestionan planes desde el panel.
- **Vet (rol vet)**: Recibe las alertas mensuales del plan.
- **Client roles**: Consultan planes vía API.

### Flujo funcional

#### 6.1 Creación de plan sanitario
```
Vet → /vet/{slug}/health-plans/create
           ▼
      HealthPlanResource (Form)
      Campos: name, year, health_plan_category_id,
              client_id, establishment_id
           ▼
      Selección de actividades por mes
      (Matriz: actividad × mes del año)
           ▼
      CreateHealthPlanAction:
      1. Crea HealthPlan
      2. Adjunta HealthActivities con pivot 'months'
         (string de números de mes separados por comas)
      3. Para cada actividad y mes seleccionado:
         - Calcula alertDate = 1er día del mes - 7 días, 16:00
         - Si alertDate > HOY: crea Alert
           { model: HealthPlan,
             notification_class: HealthPlanMonthNotification,
             text: { month: 'Enero', activities: 'Vacuna X, Vacuna Y' },
             send_at: alertDate }
         - Recipients: usuarios del vet con rol 'vet'
```

#### 6.2 Actualización del plan
```
PUT (Filament form save)
           ▼
UpdateHealthPlanAction:
1. Elimina actividades anteriores del plan
2. Elimina alertas no enviadas (delivered_at = null)
3. Crea nuevas actividades y recalcula alertas
   (mismo proceso que CreateHealthPlanAction)
```

#### 6.3 Consulta desde API
```
GET /api/v1/health_plans
       ▼
HealthPlanController@index
       ▼
Si user.vet → retorna planes del vet (filtro vet_id)
Si user.client → retorna planes del cliente (filtro client_id)

GET /api/v1/health_plans/{id}
       ▼
HealthPlanController@show
       ▼
Retorna plan con actividades organizadas por mes:
{
  id, name, year, category,
  months: {
    1: [ { activity: 'Vacuna X' } ],
    3: [ { activity: 'Desparasitación' } ],
    ...
  }
}
```

### Datos que administra

**Tabla `health_plans`**

| Campo | Descripción |
|---|---|
| `name` | Nombre del plan |
| `year` | Año del plan |
| `health_plan_category_id` | FK a la categoría |
| `client_id` | FK al cliente |
| `establishment_id` | FK al establecimiento |
| `vet_id` | FK al veterinario |
| Soft deletes | — |

**Tabla `health_plan_activity`** (pivot con datos extra)

| Campo | Descripción |
|---|---|
| `health_plan_id` | FK al plan |
| `health_activity_id` | FK a la actividad |
| `months` | String con meses separados por coma (ej: "1,3,6,9") |

**Tabla `health_activities`** (catálogo SuperAdmin)

| Campo | Descripción |
|---|---|
| `name` | Nombre de la actividad (ej: "Vacuna contra Aftosa") |
| Soft deletes | — |

**Tabla `health_plan_categories`** (catálogo SuperAdmin)

| Campo | Descripción |
|---|---|
| `name` | Nombre de la categoría (ej: "Bovinos") |
| Soft deletes | — |

### Reglas de negocio

1. Las alertas se generan exactamente **7 días antes** del primer día de cada mes a las **16:00 hs** (horario Buenos Aires).
2. Solo se crean alertas para meses **futuros**; los meses pasados son informacionales.
3. Al actualizar el plan, se eliminan todas las alertas no enviadas y se recalculan.
4. Los recipients de las alertas del plan sanitario son exclusivamente los usuarios con rol `vet` del veterinario propietario.
5. El campo `months` es un string serializado (ej: `"1,3,6,9"`), no un JSON ni un array BD.

### Validaciones

- `name`, `year`, `health_plan_category_id`, `client_id`, `establishment_id` requeridos.
- Al menos una actividad con meses seleccionados.

### Casos especiales

- Si el plan es de un año que ya pasó, se crea sin alertas.
- Un plan puede tener la misma actividad asignada a múltiples meses con distintas configuraciones.
- Las plantillas de plan (`HealthPlanTemplate`) son modelos predefinidos por SuperAdmin que el vet puede usar como base, pero no es un flujo automatizado en el código actual.

### Dependencias

- Módulo de Sistema de Alertas (creación y envío de alertas mensuales)
- Módulo de Clientes (referencia cliente y establecimiento)
- Catálogos SuperAdmin (actividades y categorías)
- Twilio (envío de recordatorios mensuales)

---

## 7. Sistema de Alertas

### Objetivo
Motor central de notificaciones del sistema. Gestiona el ciclo de vida completo de una alerta: programación, envío multi-canal, confirmación y trazabilidad.

### Usuarios involucrados
- **Todos los roles**: Son potenciales recipients de alertas.
- **Sistema (scheduler)**: Ejecuta el envío automático.

### Flujo funcional

#### 7.1 Ciclo de vida de una alerta
```
[Creación del programa/plan/evento]
           ▼
      Alert creada con send_at programado
      Alert.delivered_at = null
           ▼
[Scheduler — cada 1 minuto]
      SendAlertsNotifications::handle()
           ▼
      Busca: send_at <= NOW() AND delivered_at IS NULL
           ▼
      Para cada alerta → para cada recipient:
        recipient.notify(new $alert->notification_class($alert))
           ▼
      alert.delivered_at = now()  ← se marca en el loop interno
      alert.save()
           ▼
[Si require_confirmation = true]
      Usuario confirma desde la app:
      POST /api/v1/tasks/{alert_id}/complete
           ▼
      alert.confirmed_at = now()
```

#### 7.2 Canales de envío
```
TwilioNotificationsChannel
    ▼
    toTwilio(notifiable):
      - Usa notifiable.formated_phone (con prefijo '+')
      - Selecciona Twilio Content SID según tipo de notificación
      - Construye ContentVariables (JSON numbered 1-N)
      - Llama Twilio API → messages()->create()

WhatsAppNotificationsChannel
    ▼
    (Alternativo — referenciado pero no completamente implementado)
```

#### 7.3 Tipos de notificación y sus plantillas Twilio

| Clase Notificación | Content SID Twilio | Trigger |
|---|---|---|
| `ProgramCreatedNotification` | `HXf21519b33fc95a762315e0a9289c101c` | Al crear un programa |
| `ProgramCanceledNotification` | `HXbf82a4e83175759cf88d9ab5252a7cbd` | Al cancelar un programa |
| `EventNotification` | `HX8b44cd3f5741c6780d39d396e6d7dec3` | Alerta de evento de agenda |
| `HealthPlanMonthNotification` | `HXb734a385bc5cdc6bbe1dfac9dee85fb7` | Recordatorio mensual plan sanitario |
| `ProgramTaskNotification` | 5 SIDs (según N° de mensajes) | Alerta de tarea de protocolo |

`ProgramTaskNotification` tiene lógica especial: el campo `text` de la alerta es un JSON con hasta 5 mensajes. Según cuántos mensajes tenga, usa uno de los 5 Content SIDs.

#### 7.4 Formato del campo `text` en Alert

```json
// ProgramTaskNotification (texto es JSON array de strings)
["Aplicar GnRH", "Colocar DIU"]

// HealthPlanMonthNotification
{ "month": "Enero", "activities": "Vacuna Aftosa, Desparasitación" }

// EventNotification
{ "event_name": "Tacto Rectoscopía", "date": "15/06/2026", "time": "09:00" }
```

### Datos que administra

**Tabla `alerts`**

| Campo | Descripción |
|---|---|
| `model_id` | ID del modelo padre (Program, HealthPlan, Event) |
| `model_class` | FQCN del modelo padre (polimórfico) |
| `notification_class` | FQCN de la clase de notificación a instanciar |
| `text` | JSON con el contenido del mensaje |
| `send_at` | Timestamp programado de envío |
| `delivered_at` | Timestamp de entrega (null = pendiente) |
| `require_confirmation` | Boolean: ¿necesita confirmación del usuario? |
| `confirmed_at` | Timestamp de confirmación (null = pendiente) |

**Tabla `alert_user`** (pivot recipients)

| Campo | Descripción |
|---|---|
| `alert_id` | FK a la alerta |
| `user_id` | FK al usuario recipient |

### Reglas de negocio

1. Una alerta puede tener múltiples recipients (N:M via `alert_user`).
2. Una alerta se considera **entregada** cuando `delivered_at != null`.
3. Una alerta con `require_confirmation = true` y `confirmed_at = null` está **pendiente de confirmación**.
4. El sistema NO reintenta alertas fallidas: si Twilio falla, la alerta queda marcada como entregada de todas formas (el error solo se loguea).
5. El campo `notification_class` permite extender el sistema con nuevas notificaciones sin modificar el job.

### Casos especiales / Bugs conocidos

- **Bug en loop de entrega**: `delivered_at` se asigna dentro del loop de recipients. Esto significa que si el primer recipient es exitoso, los siguientes ya no recibirán la notificación porque en la próxima iteración del scheduler la alerta ya está marcada como entregada.
- **Sin reintentos**: No existe mecanismo de retry para alertas fallidas.
- **Alertas del pasado**: Al crear un programa o plan, las alertas cuya `send_at` sería anterior al inicio del día actual no se crean.

### Dependencias

- Twilio SDK (canal de envío)
- Laravel Scheduler (`everyMinute()`)
- Módulo de Programas, Planes Sanitarios y Agenda (creadores de alertas)
- Módulo de Autenticación (recipients = users)

---

## 8. Agenda y Eventos

### Objetivo
Gestionar el calendario de eventos del veterinario (visitas, controles, otros) con opción de programar recordatorios automáticos.

### Usuarios involucrados
- **Vet / Vet roles**: Crean y gestionan eventos.
- **Vet (quien crea el evento)**: Recibe el recordatorio.

### Flujo funcional

#### 8.1 Creación de evento con recordatorio
```
POST /api/v1/agenda
{ name, client_id, event_type, date, time,
  days_before?, alert_time? }
           ▼
AgendaController@store
           ▼
Crea Event { vet_id=auth.vet.id, name, client_id,
             event_type, date, time }
           ▼
Si days_before y alert_time presentes:
  send_at = event.date - days_before días, con alert_time
  Crea Alert:
    { model: Event,
      notification_class: EventNotification,
      text: JSON { event_name, date, time },
      send_at }
  Recipient: usuario autenticado
```

#### 8.2 Tipos de evento

| Valor | Descripción |
|---|---|
| `Tacto` | Examen táctil |
| `Visita` | Visita al establecimiento |
| `Control sanitario` | Control de salud |
| `Cumpleaños` | Evento de cumpleaños |
| `Otros` | Tipo genérico |

#### 8.3 Actualización de evento
```
PUT /api/v1/agenda/{event_id}
           ▼
AgendaController@update
           ▼
Actualiza campos del event
           ▼
Si alerta existente no enviada (delivered_at = null):
  Recalcula send_at y actualiza Alert existente
Si alerta ya enviada:
  Crea nueva Alert si days_before especificado
```

#### 8.4 Consulta
```
GET /api/v1/agenda
       → Todos los eventos del vet del usuario autenticado
       → Incluye: client, alert (si existe)

GET /api/v1/agenda/{id}
       → Detalle del evento con alert y recipients
```

### Datos que administra

**Tabla `events`**

| Campo | Descripción |
|---|---|
| `name` | Nombre del evento |
| `event_type` | Tipo (Tacto, Visita, etc.) |
| `date` | Fecha del evento |
| `time` | Hora del evento |
| `vet_id` | FK al veterinario |
| `client_id` | FK al cliente (nullable) |

### Reglas de negocio

1. Solo los usuarios del vet pueden crear y ver eventos de ese vet.
2. El recordatorio es opcional: si no se especifican `days_before` y `alert_time`, no se crea alerta.
3. Solo el usuario que crea el evento es recipient del recordatorio.
4. Si el evento se actualiza y la alerta original ya fue enviada, se puede crear una nueva alerta.

### Casos especiales

- No hay endpoint de eliminación de eventos en la API (solo en el panel Filament).
- Si `days_before = 0`, la alerta se programa para el mismo día del evento.

### Dependencias

- Módulo de Sistema de Alertas (crea y envía recordatorios)
- Módulo de Clientes (vinculación opcional con cliente)
- Módulo de Autenticación (vet_id del usuario autenticado)

---

## 9. Importación de Clientes

### Objetivo
Carga masiva de clientes, establecimientos y usuarios desde un archivo Excel con un formato predefinido.

### Usuarios involucrados
- **Vet / Vet Assistant**: Suben el archivo desde el panel.
- **Sistema (scheduler)**: Procesa los archivos pendientes cada 10 minutos.

### Flujo funcional

#### 9.1 Subida de archivo
```
Vet → /vet/{slug}/imports/create
           ▼
ImportResource (Form)
Sube archivo Excel (.xlsx, .xls, .csv)
           ▼
Import creado con { vet_id, file_path, status: 'PENDING' }
```

#### 9.2 Procesamiento (Scheduler — cada 10 minutos)
```
ImportClients::handle()
           ▼
Busca Import con status = 'PENDING'
           ▼
Import.status = 'ON_GOING'
           ▼
ClientsImport::import(file_path)
           ▼
Para cada fila del Excel:
  ┌─────────────────────────────────────────────┐
  │ Col [0] name_client     (nombre del cliente) │
  │ Col [1] cuit_cuil       (CUIT/CUIL único)   │
  │ Col [2] establishment   (nombre estab.)      │
  │ Col [3] city            (ciudad estab.)      │
  │                                              │
  │ Col [4] owner_name      ┐                   │
  │ Col [5] owner_area_code ├→ rol CLIENT_OWNER  │
  │ Col [6] owner_phone     ┘                   │
  │                                              │
  │ Col [7]  manager_name      ┐                │
  │ Col [8]  manager_area_code ├→ CLIENT_MANAGER│
  │ Col [9]  manager_phone     ┘                │
  │                                              │
  │ Col [10] admin_name      ┐                  │
  │ Col [11] admin_area_code ├→ CLIENT_ADMIN    │
  │ Col [12] admin_phone     ┘                  │
  └─────────────────────────────────────────────┘
           ▼
  Para cada fila:
  1. Buscar Client por cuit_cuil
     - Si existe (trashed): restore + update
     - Si no existe: create
  2. Vincular Client con Vet (client_vet pivot)
  3. Upsert Establishment por nombre
  4. Para cada rol (owner/manager/administrative):
     - Si los 3 campos están presentes:
       - username = area_code + phone (normalizado)
       - Si usuario existe (trashed): restore
       - Si no existe: create { name, username, phone,
                                password: '123456',
                                client_id, vet_id }
       - Asignar rol correspondiente
     - Si algún campo falta → omitir ese usuario
           ▼
  Si error en una fila:
    ImportLog.create({ import_id, line_number, error })
    Continúa con la siguiente fila (no rollback global)
           ▼
Import.status = 'COMPLETED' o 'ERROR' (si todos fallaron)
Import.completed_at = now()
```

#### 9.3 Descarga de plantilla
```
GET /clients_export
       ▼
ClientsExampleExport (Maatwebsite)
       ▼
Genera Excel con encabezados de ejemplo
Retorna descarga
```

### Normalización de teléfonos

```
Formato de entrada:  area_code="011", phone="15-1234-5678"
Normalización:
  1. Elimina '0' inicial del area_code: "11"
  2. Elimina '15' inicial del phone: "1234-5678"
  3. Elimina guiones: "12345678"
  4. Formato final: "5411" + "12345678" → "541112345678"

username generado: "1112345678" (area_code + phone sin prefijo)
```

### Datos que administra

**Tabla `imports`**

| Campo | Descripción |
|---|---|
| `vet_id` | FK al veterinario |
| `file_path` | Ruta del archivo subido |
| `status` | PENDING / ON_GOING / COMPLETED / ERROR |
| `completed_at` | Timestamp de finalización |

**Tabla `import_logs`**

| Campo | Descripción |
|---|---|
| `import_id` | FK a la importación |
| `line_number` | Número de fila con error |
| `error` | Descripción del error |

### Reglas de negocio

1. Solo se procesa **un import pendiente por ejecución** del scheduler.
2. Los errores en filas individuales no detienen el procesamiento del resto del archivo.
3. El password por defecto para usuarios importados es `'123456'` (sin hash en el seeder, hasheado al guardar vía modelo).
4. Si el CUIT/CUIL ya existe y el cliente está en soft delete, se restaura en lugar de crear uno nuevo.
5. El username se genera concatenando área + teléfono; si ya existe, se restaura el usuario existente.
6. Cada fila puede crear hasta 3 usuarios (uno por cada rol de contacto).

### Casos especiales

- Si las columnas de un rol están parcialmente completas (ej: nombre sin teléfono), ese usuario es omitido sin error.
- El scheduler solo corre `ImportClients` cada 10 minutos; archivos grandes pueden tardar en procesarse.
- No hay límite de filas validado; archivos muy grandes pueden exceder el timeout del job en queue sync.

### Dependencias

- Módulo de Clientes (crea/restaura clientes, establecimientos, usuarios)
- Módulo de Autenticación (crea usuarios con roles)
- Maatwebsite/Excel (parsing del archivo)
- Laravel Scheduler (dispara el procesamiento)

---

## 10. Facturación

### Objetivo
Registrar las facturas y líneas de factura del veterinario para su consulta y seguimiento interno.

### Usuarios involucrados
- **Vet / Vet Administrative**: Crean y visualizan facturas desde el panel.

### Flujo funcional

```
Vet → /vet/{slug}/invoices
           ▼
InvoiceResource
Lista facturas del vet (filtrado por vet_id)
           ▼
InvoiceResource::create / edit
Campos: code, date, due_date, status, type,
        currency_code (ARS por defecto), amount
           ▼
InvoiceLinesRelationManager:
  Agrega líneas de detalle:
  { description, price, tax, discount, total }
```

### Datos que administra

**Tabla `invoices`**

| Campo | Descripción |
|---|---|
| `code` | Número/código de factura |
| `date` | Fecha de emisión |
| `due_date` | Fecha de vencimiento |
| `status` | Estado de la factura |
| `type` | Tipo (default: 'invoice') |
| `currency_code` | Moneda (default: ARS) |
| `amount` | Monto total |
| `vet_id` | FK al veterinario |

**Tabla `invoice_lines`**

| Campo | Descripción |
|---|---|
| `invoice_id` | FK a la factura |
| `description` | Descripción del ítem |
| `price` | Precio unitario |
| `tax` | Impuesto |
| `discount` | Descuento |
| `total` | Total de la línea |

### Reglas de negocio

1. Las facturas son exclusivas del vet propietario (filtradas por `vet_id`).
2. El módulo está implementado como registro manual; no hay integración con ningún servicio de facturación electrónica.

### Casos especiales

- El módulo no expone endpoints en la API; es exclusivo del panel Filament.
- No hay lógica de cálculo automático de totales entre líneas.

### Dependencias

- Módulo de Autenticación (vet_id)

---

## 11. WhatsApp y Opt-Out

### Objetivo
Gestionar los mensajes entrantes de WhatsApp (webhook) y mantener una lista de números que solicitaron no recibir más mensajes.

### Usuarios involucrados
- **Sistema**: Recibe webhooks de Twilio/WhatsApp.
- **Destinatarios finales**: Usuarios que envían 'baja' o 'alta' por WhatsApp.

### Flujo funcional

#### 11.1 Recepción del webhook
```
POST /whatsapp-webhook       (web)
POST /api/v1/whatsapp-webhook (api)
           ▼
WhatsAppWebhookController@index / WhatsAppController@receive
           ▼
Extrae: From (número), Body (mensaje)
           ▼
Normaliza número:
  '549...' → '54...' (elimina '9' extra del prefijo Argentina)
           ▼
Si Body.lower() == 'baja':
  OptOutNumber::firstOrCreate({ phone: normalized_number })
  Responde: "Has sido dado de baja de las notificaciones de SAV"

Si Body.lower() == 'alta':
  OptOutNumber::where(phone)->delete()
  Responde: "Has sido dado de alta de las notificaciones de SAV"

Otro caso:
  Responde: "Para darte de baja responde BAJA. Para recibir alertas nuevamente responde ALTA"
```

#### 11.2 Supresión de mensajes salientes

Antes de enviar por Twilio, el sistema verifica:
```
User.formated_phone → Busca en OptOutNumber
Si número en lista → No envía la notificación
```

### Datos que administra

**Tabla `opt_out_numbers`**

| Campo | Descripción |
|---|---|
| `phone` | Número normalizado (54XXXXXXXXXXX) |

### Reglas de negocio

1. La normalización elimina el '9' que Argentina inserta en números de celular internacionales.
2. 'baja' y 'alta' son case-insensitive.
3. Un número en `opt_out_numbers` no recibirá ningún mensaje de WhatsApp, de ningún vet.
4. La supresión es **global**: afecta a todos los vets, no solo al vet desde el que se suscribió.

### Casos especiales

- El webhook no valida la firma HMAC de Twilio → **riesgo de inyección de opt-outs falsos**.
- Hay dos rutas para el mismo webhook (web y api); ambas tienen handlers similares pero separados.

### Dependencias

- Twilio (canal de mensajería)
- Módulo de Sistema de Alertas (consulta opt-out antes de enviar)

---

## 12. Soporte y Tutoriales

### Objetivo
Proveer un canal de comunicación interno entre los usuarios del vet y el equipo de soporte, y gestionar material de ayuda (tutoriales) accesible desde el panel.

### Usuarios involucrados
- **Vet / todos los roles vet**: Envían mensajes de soporte.
- **SuperAdmin**: Gestiona todos los mensajes de soporte.

### Flujo funcional

#### 12.1 Mensajes de soporte
```
Usuario → /vet/{slug}/support-messages/create
               ▼
SupportMessageResource (Form)
{ subject, message }
               ▼
SupportMessage.create({ user_id, vet_id, subject, message, status })

SuperAdmin → /superadmin/support-messages
                  → Ve todos los mensajes de soporte
                  → Puede actualizar status
```

#### 12.2 Tutoriales
```
SuperAdmin → /superadmin/tutorials/create
               ▼
TutorialResource
{ title, url (video), order }

Vet → /vet/{slug}/tutorials
          → Solo lectura
          → Widget Tutorials en Dashboard
          → Ordenados por campo 'order'
```

### Datos que administra

**Tabla `support_messages`**

| Campo | Descripción |
|---|---|
| `user_id` | FK al usuario que envía |
| `vet_id` | FK al vet del usuario |
| `subject` | Asunto |
| `message` | Cuerpo del mensaje |
| `status` | Estado del ticket |

**Tabla `tutorials`**

| Campo | Descripción |
|---|---|
| `title` | Título del tutorial |
| `url` | URL del video |
| `order` | Orden de aparición |

### Dependencias

- Módulo de Autenticación (user_id, vet_id)

---

## 13. Configuración del Panel Vet

### Objetivo
Permitir al veterinario personalizar la apariencia de su panel con logo, título y subtítulo propios.

### Usuarios involucrados
- **Vet**: Configura su identidad visual.

### Flujo funcional

```
Vet → /vet/{slug}/program-settings
           ▼
ProgramSettingResource
{ logo (upload), title, subtitle }
           ▼
ProgramSetting.updateOrCreate({ vet_id }, { logo, title, subtitle })
           ▼
ApplyTenantTheme (Middleware):
  Carga ProgramSetting del vet actual
  Aplica logo al brandLogo del panel Filament
```

### Datos que administra

**Tabla `program_settings`**

| Campo | Descripción |
|---|---|
| `vet_id` | FK al veterinario (único) |
| `logo` | Ruta del logo subido |
| `title` | Título del panel |
| `subtitle` | Subtítulo del panel |

### Reglas de negocio

1. Solo existe un registro de configuración por vet.
2. El logo se aplica dinámicamente en cada request del panel via middleware.
3. El logo por defecto se sirve desde `storage/vets/default/logo.jpg`.

### Dependencias

- `ApplyTenantTheme` Middleware
- Filament Panel (brandLogo dinámico)

---

## Apéndice A — Catálogos Administrados por SuperAdmin

Los siguientes módulos son catálogos sin lógica de negocio compleja, gestionados exclusivamente desde el panel SuperAdmin:

| Catálogo | Recurso Filament | Propósito |
|---|---|---|
| Técnicas | `TechniqueResource` | Catálogo de técnicas reproductivas (jerárquico) |
| Actividades Sanitarias | `HealthActivityResource` | Actividades usadas en planes sanitarios |
| Categorías de Plan | `HealthPlanCategoryResource` | Clasificación de planes sanitarios |
| Plantillas de Plan | `HealthPlanTemplateResource` | Planes predefinidos con actividades y meses |
| Vacunas | `VaccineResource` | Catálogo de vacunas (con RelationManager de protocolos) |

---

## Apéndice B — Exportaciones Excel Disponibles

| Nombre | Ruta | Contenido |
|---|---|---|
| Plantilla de clientes | `GET /clients_export` | Excel con formato para importación masiva de clientes |
| Plantilla de tareas de protocolo | `GET /protocol_task_export` | Excel con formato para importación masiva de tareas |
| PDF del programa | `GET /programs/{id}/get_file` | PDF generado con DomPDF del detalle del programa |

---

## Apéndice C — Dependencias entre Módulos

```
Autenticación
    └──→ Todos los módulos (vet_id, user_id)

Veterinarios
    └──→ Clientes (multi-tenancy)
    └──→ Técnicas y Protocolos (vet_id scope)
    └──→ Programas (vet_id)
    └──→ Planes Sanitarios (vet_id)
    └──→ Agenda (vet_id)
    └──→ Facturación (vet_id)
    └──→ Configuración del Panel (vet_id)

Clientes
    └──→ Programas (client_id, establishment_id)
    └──→ Planes Sanitarios (client_id, establishment_id)
    └──→ Agenda (client_id)
    └──→ Importación (crea clientes)

Técnicas y Protocolos
    └──→ Programas (protocol_id → genera alertas)

Programas
    └──→ Sistema de Alertas (crea alertas de protocolo)
    └──→ Notificación: creación, cancelación

Planes Sanitarios
    └──→ Sistema de Alertas (crea alertas mensuales)

Agenda
    └──→ Sistema de Alertas (crea recordatorios)

Sistema de Alertas
    └──→ Twilio / WhatsApp (canal de envío)
    └──→ WhatsApp Opt-Out (supresión de envíos)

Importación de Clientes
    └──→ Clientes (crea/restaura datos)
    └──→ Autenticación (crea usuarios con roles)
```

---

*Documento generado el 2026-06-20. Basado en análisis del código fuente rama `develop`.*
