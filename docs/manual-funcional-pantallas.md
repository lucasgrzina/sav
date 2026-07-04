# Manual Funcional de Pantallas — SAV

> Basado en análisis de rutas, controladores, recursos Filament 3 y vistas Blade.  
> Fecha: 2026-06-20

---

## Índice

1. [Sitemap completo](#1-sitemap-completo)
2. [Menús detectados](#2-menús-detectados)
3. [Flujo de navegación](#3-flujo-de-navegación)
4. [Permisos por pantalla](#4-permisos-por-pantalla)
5. [Pantallas — Sitio público](#5-pantallas--sitio-público)
6. [Pantallas — API móvil](#6-pantallas--api-móvil)
7. [Pantallas — Panel Vet](#7-pantallas--panel-vet)
8. [Pantallas — Panel SuperAdmin](#8-pantallas--panel-superadmin)

---

## 1. Sitemap completo

```
SAV
├── SITIO PÚBLICO (/)
│   ├── /                        Landing page
│   ├── /register                Registro de veterinaria
│   ├── /terms-conditions        Términos y condiciones
│   └── /privacy-policy          Política de privacidad
│
├── PANEL VET (/vet)
│   ├── /vet/login               Login veterinaria
│   ├── /vet/{slug}/             Dashboard
│   │
│   ├── — GRUPO: Reproducción —
│   ├── /vet/{slug}/programs              Lista de programas
│   ├── /vet/{slug}/programs/create       Crear programa
│   ├── /vet/{slug}/programs/{id}         Ver programa
│   ├── /vet/{slug}/programs/{id}/edit    Editar programa
│   │
│   ├── /vet/{slug}/protocols             Lista de protocolos
│   ├── /vet/{slug}/protocols/create      Crear protocolo
│   ├── /vet/{slug}/protocols/{id}/edit   Editar protocolo
│   │
│   ├── — GRUPO: Clientes —
│   ├── /vet/{slug}/clients               Lista de clientes
│   ├── /vet/{slug}/clients/create        Crear cliente
│   ├── /vet/{slug}/clients/{id}/edit     Editar cliente (+ establecimientos, usuarios, planes)
│   │
│   ├── — GRUPO: Health —
│   ├── /vet/{slug}/health-plans          Lista de planes de salud
│   ├── /vet/{slug}/health-plans/create   Crear plan de salud
│   ├── /vet/{slug}/health-plans/{id}/edit  Editar plan de salud
│   │
│   ├── — GRUPO: Agenda —
│   ├── /vet/{slug}/agendas               Lista de eventos
│   ├── /vet/{slug}/agendas/create        Crear evento
│   ├── /vet/{slug}/agendas/{id}/edit     Editar evento
│   │
│   ├── — GRUPO: Settings —
│   ├── /vet/{slug}/users                 Lista de usuarios de la vet
│   ├── /vet/{slug}/users/create          Crear usuario
│   ├── /vet/{slug}/users/{id}/edit       Editar usuario
│   ├── /vet/{slug}/program-settings      Configuración de PDF (acceso directo sin crear)
│   ├── /vet/{slug}/imports               Lista de importaciones
│   ├── /vet/{slug}/imports/create        Importar clientes
│   ├── /vet/{slug}/imports/{id}          Ver resultado de importación
│   │
│   ├── — GRUPO: Support —
│   ├── /vet/{slug}/support-messages      Lista de tickets de soporte
│   ├── /vet/{slug}/support-messages/create  Crear ticket
│   ├── /vet/{slug}/support-messages/{id}    Ver ticket
│   │
│   └── — OTRAS —
│       ├── /vet/{slug}/tutorials          Lista de tutoriales
│       ├── /vet/{slug}/tutorials/{id}     Ver tutorial
│       └── /edit-profile                  Editar perfil del usuario
│
├── PANEL SUPERADMIN (/superadmin)
│   ├── /superadmin/login         Login superadmin
│   ├── /superadmin/              Dashboard
│   ├── /superadmin/vets          Lista de veterinarias
│   ├── /superadmin/vets/create   Nueva veterinaria
│   ├── /superadmin/vets/{id}/edit  Editar veterinaria
│   ├── /superadmin/techniques    Lista de técnicas
│   ├── /superadmin/health-plan-categories  Categorías de planes
│   ├── /superadmin/health-plan-templates   Plantillas de planes
│   ├── /superadmin/health-activities       Actividades de salud
│   └── /superadmin/support-messages        Tickets de soporte
│
├── API REST (/api/v1)
│   ├── POST   /login
│   ├── POST   /logout
│   ├── GET    /user
│   ├── POST   /change-password
│   ├── GET    /techniques
│   ├── GET    /techniques/protocols
│   ├── GET    /techniques/{id}/protocols
│   ├── GET    /clients
│   ├── GET    /clients/{id}/establishments
│   ├── GET    /clients/{id}/managers
│   ├── GET    /clients/{estab_id}/groups
│   ├── GET    /programs
│   ├── GET    /programs/{id}
│   ├── POST   /programs
│   ├── PUT    /programs/{id}
│   ├── POST   /programs/{id}/cancel
│   ├── POST   /tasks/{alert_id}/complete
│   ├── GET    /health_plans
│   ├── GET    /health_plans/{id}
│   ├── GET    /agenda
│   ├── GET    /agenda/{id}
│   ├── POST   /agenda
│   ├── PUT    /agenda/{id}
│   └── GET    /vets/users
│
└── WEBHOOKS Y DESCARGAS
    ├── POST   /whatsapp-webhook   Webhook WhatsApp Cloud API
    ├── GET    /protocol_task_export   Descargar plantilla Excel tareas
    ├── GET    /clients_export         Descargar plantilla Excel clientes
    └── GET    /programs/{id}/get_file Descargar PDF de programa (URL firmada)
```

---

## 2. Menús detectados

### 2.1 Menú del Panel Vet

El menú lateral se organiza en grupos con ícono y etiqueta. La visibilidad de algunas entradas depende del rol del usuario autenticado.

| Grupo | Ítem | Ícono | Visible para |
|---|---|---|---|
| *(sin grupo)* | Dashboard | heroicon-o-home | Todos |
| **Reproducción** | Programas | heroicon-o-calendar | Todos |
| **Reproducción** | Protocolos | heroicon-o-document-text | Todos |
| **Clientes** | Clientes | heroicon-o-users | Todos |
| **Health** | Planes de Salud | heroicon-o-heart | Todos |
| **Agenda** | Agenda | heroicon-o-calendar-days | Todos |
| **Settings** | Usuarios | heroicon-o-user-group | Solo VET_VET |
| **Settings** | Configuración PDF | *(sin ítem en nav)* | Acceso directo |
| **Settings** | Importaciones | heroicon-o-arrow-up-tray | Todos |
| **Support** | Soporte | heroicon-o-chat-bubble-left | Todos |
| *(sin grupo)* | Tutoriales | heroicon-o-play | Todos |

**Widgets del Dashboard:**
- `ProgramTypeWidget` — Resumen de programas por tipo de técnica
- `ActivePrograms` — Cantidad de programas activos
- `Tutorials` — Acceso rápido a tutoriales

### 2.2 Menú del Panel SuperAdmin

| Grupo | Ítem | Descripción |
|---|---|---|
| *(sin grupo)* | Veterinarias | Gestión de organizaciones vet |
| *(sin grupo)* | Técnicas | Catálogo de técnicas reproductivas |
| **Health** | Categorías de Planes | Tipos de planes de salud |
| **Health** | Plantillas de Planes | Templates reutilizables |
| **Health** | Actividades de Salud | Catálogo de actividades |
| *(sin grupo)* | Soporte | Tickets de soporte de todos los vets |

### 2.3 Menú del Sitio Público

El header de la landing page incluye:
- Logo SAV
- Enlace a registro (`/register`)
- Enlace a términos y condiciones
- Enlace a política de privacidad

---

## 3. Flujo de navegación

### 3.1 Flujo de registro de veterinaria

```
/ (Landing)
    │
    └─► /register (Formulario de alta)
            │
            ├─► Validación falla → vuelve al formulario con errores
            │
            └─► Éxito → Redirige a /vet/login?from_register=true
                            │
                            └─► Login exitoso → /vet/{slug}/
```

### 3.2 Flujo de creación de programa (panel Vet)

```
/vet/{slug}/programs (Lista)
    │
    └─► /vet/{slug}/programs/create (Formulario)
            │
            ├─► 1. Selecciona Cliente
            │       └─► Carga dinámicamente Establecimientos y Managers
            ├─► 2. Selecciona Establecimiento
            ├─► 3. Selecciona Técnica padre (MOET, FIV, IATF)
            │       └─► Carga Sub-técnicas
            ├─► 4. Selecciona Sub-técnica
            │       └─► Carga Protocolos de esa técnica
            ├─► 5. Selecciona Protocolo
            ├─► 6. Asigna Gestores (vets y clientes)
            ├─► 7. Define Fecha Objetivo
            └─► 8. Guarda
                    │
                    └─► Éxito → /vet/{slug}/programs/{id} (Vista detalle)
                                    │
                                    ├─► Ver alertas planificadas
                                    ├─► Descargar PDF
                                    ├─► Editar
                                    └─► Cancelar
```

### 3.3 Flujo de gestión de clientes

```
/vet/{slug}/clients (Lista)
    │
    ├─► /vet/{slug}/clients/create (Alta rápida)
    │
    └─► /vet/{slug}/clients/{id}/edit (Ficha completa)
            │
            ├─► Tab "Establecimientos"
            │       ├─► Ver/crear/editar establecimientos
            │       └─► Mapa con coordenadas GPS
            │
            ├─► Tab "Usuarios del cliente"
            │       ├─► Ver/crear usuarios del portal del cliente
            │       └─► El alta envía contraseña por WhatsApp/email
            │
            └─► Tab "Planes de Salud"
                    ├─► Crear desde plantilla
                    ├─► Crear desde cero
                    └─► Ver/editar actividades y meses
```

### 3.4 Flujo de importación de clientes

```
/vet/{slug}/clients (Lista)
    │
    └─► Botón "Importar" → /vet/{slug}/imports/create
            │
            ├─► Descarga plantilla Excel
            ├─► Sube archivo Excel
            └─► Guarda → job async se encola
                    │
                    └─► /vet/{slug}/imports/{id} (Resultado)
                            ├─► Estado: PENDING → ON_GOING → COMPLETED / ERROR
                            └─► Si error → enlace a crear ticket de soporte
```

### 3.5 Flujo de alertas automáticas (background)

```
Scheduler (cada minuto)
    │
    └─► SendAlertsNotifications job
            │
            └─► Busca alerts donde send_at <= ahora Y delivered_at IS NULL
                    │
                    └─► Por cada recipient:
                            ├─► ¿Número en opt_out_numbers? → Saltear
                            ├─► Canal preferido (WhatsApp → SMS → Email)
                            └─► Marca delivered_at = ahora
```

---

## 4. Permisos por pantalla

| Pantalla / Endpoint | Rol requerido | Observaciones |
|---|---|---|
| Sitio público (`/`, `/register`) | Anónimo | Sin autenticación |
| Panel Vet — todas las pantallas | `VET_VET` o `VET_ASSISTANT` | Requiere login + ser miembro del tenant |
| Panel Vet — Usuarios (`/users`) | Solo `VET_VET` | VET_ASSISTANT no ve esta entrada de menú |
| Panel Vet — Configuración PDF | Solo `VET_VET` | Sin navegación; acceso directo |
| Panel SuperAdmin — todas | `SUPERADMIN` | Panel separado en `/superadmin` |
| API `POST /programs` | `VET_VET` o `VET_ASSISTANT` | Solo roles de vet pueden crear |
| API `GET /programs` | Cualquier autenticado | Vets ven todos; clientes solo los suyos |
| API `GET /health_plans` | Cualquier autenticado | Filtrado por vet_id o client_id |
| API `GET /agenda` | Solo roles vet | Clientes no tienen acceso a agenda |
| API `POST /agenda` | Solo roles vet | |
| API `POST /tasks/{id}/complete` | Cualquier autenticado | Solo confirma alertas propias |
| PDF de programa (`/programs/{id}/get_file`) | URL firmada | Sin login; requiere firma válida en URL |

---

## 5. Pantallas — Sitio público

---

### P-01 · Landing Page

**URL**: `/`  
**Rol**: Anónimo  
**Controlador**: `FrontController@index`  
**Vista**: `resources/views/front/index.blade.php`

**Descripción**  
Página de presentación del producto. Explica qué es SAV, sus beneficios y cómo suscribirse.

**Secciones de la pantalla**

| Sección | Contenido |
|---|---|
| Header | Logo + enlaces a registro, términos y privacidad |
| Hero | Texto principal + CTA "Suscribite para una prueba gratuita" → `/register` |
| ¿Qué es SAV? | Descripción del sistema de alertas veterinarias |
| Alertas en el momento indicado | Explicación de notificaciones WhatsApp |
| FAQ | 5 preguntas expandibles con JavaScript |
| Footer | Créditos del equipo veterinario y de desarrollo |

**Funcionalidad**
- El CTA principal dirige a `/register`
- Las FAQ se expanden/colapsan con JavaScript sin recarga
- Sin formularios ni autenticación

---

### P-02 · Registro de Veterinaria

**URL**: `/register`  
**Rol**: Anónimo  
**Controlador**: `Auth\RegisterController@register` (POST)  
**Vista**: `resources/views/front/register.blade.php`

**Descripción**  
Formulario de alta para nuevas organizaciones veterinarias. Crea simultáneamente la Vet y su usuario administrador (rol VET_VET).

**Campos del formulario**

| Campo | Tipo | Obligatorio | Validación |
|---|---|---|---|
| Nombre de la veterinaria | text | Sí | — |
| CUIT | text | Sí | 11 dígitos, único en `vets.cuit` |
| Dirección | text | Sí | — |
| Email | email | Sí | Email válido |
| Teléfono | text | Sí | — |
| N° de matrícula | text | Sí | — |
| Colegio veterinario | text | No | — |
| Universidad | text | No | — |
| Contraseña | password | Sí | Confirmación requerida |
| Acepto términos | checkbox | Sí | — |
| Código de área WhatsApp | text | Sí | Sin el 0 inicial |
| Número WhatsApp | text | Sí | Sin el 15 |

**Comportamiento**
- Integra Google reCAPTCHA v3 (validación invisible)
- El número WhatsApp se formatea internamente como `54{area}{numero}`
- Al registrar exitosamente: redirige a `/vet/login?from_register=true`
- Errores de validación: vuelve al formulario con mensajes bajo cada campo

---

### P-03 · Términos y Condiciones

**URL**: `/terms-conditions`  
**Rol**: Anónimo  
**Vista**: `resources/views/front/terms-conditions.blade.php`

Documento legal estático. Sin interacción.

---

### P-04 · Política de Privacidad

**URL**: `/privacy-policy`  
**Rol**: Anónimo  
**Vista**: `resources/views/front/privacy-policy.blade.php`

Documento legal estático. Sin interacción.

---

## 6. Pantallas — API móvil

La app mobile (no incluida en este repositorio) consume los endpoints listados. Se documenta aquí la funcionalidad de cada endpoint como "pantalla lógica".

---

### A-01 · Login

**Endpoint**: `POST /api/v1/login`  
**Autenticación**: Ninguna

**Request**
```json
{ "email": "usuario@ejemplo.com", "password": "1234", "device_name": "iPhone" }
```

**Respuesta exitosa**
```json
{
  "token": "1|xxxxxxxxxxxx",
  "user": {
    "name": "Nombre",
    "email": "email",
    "role": "vet_vet | vet_assistant | client_manager | client_assistant",
    "vet": { "name": "Veterinaria SA" },
    "client": { "name": "Productor Pérez" },
    "vet_id": 1,
    "client_id": null
  }
}
```

**Notas**
- El campo `email` en el request corresponde al `username` del usuario (internamente es el CUIT)
- El token se debe incluir en todas las llamadas posteriores: `Authorization: Bearer {token}`
- Si `must_change_password = true`, la app debe dirigir al cambio de contraseña antes de continuar

---

### A-02 · Cambio de contraseña

**Endpoint**: `POST /api/v1/change-password`  
**Autenticación**: Requerida

**Request**
```json
{ "current_password": "vieja", "password": "nueva", "password_confirmation": "nueva" }
```

La nueva contraseña debe tener mínimo 6 caracteres y coincidir con `password_confirmation`.

---

### A-03 · Lista de técnicas

**Endpoint**: `GET /api/v1/techniques?type=technique`  
**Autenticación**: Requerida

Retorna técnicas de nivel raíz (sin padre). El parámetro `type` acepta `technique` (default) o `vaccine`. Cada técnica incluye sus hijos (sub-técnicas).

---

### A-04 · Lista de protocolos

**Endpoint**: `GET /api/v1/techniques/{id}/protocols`  
**Autenticación**: Requerida

Protocolos disponibles para una técnica dada. Incluye protocolos globales (sin `vet_id`) más los propios de la vet autenticada.

---

### A-05 · Lista de clientes

**Endpoint**: `GET /api/v1/clients`  
**Autenticación**: Requerida (rol vet)

Lista de clientes de la veterinaria con sus establecimientos y usuarios (managers).

---

### A-06 · Grupos de donantes

**Endpoint**: `GET /api/v1/clients/{establishment_id}/groups`  
**Autenticación**: Requerida

Lista de grupos de animales de un establecimiento, incluyendo los animales (RP donor) de cada grupo.

---

### A-07 · Lista de programas

**Endpoint**: `GET /api/v1/programs`  
**Autenticación**: Requerida

**Comportamiento según rol**:
- Roles vet (`vet_vet`, `vet_assistant`): retorna todos los programas de la vet
- Roles cliente (`client_manager`, `client_assistant`): retorna solo los programas del cliente al que pertenece el usuario

Cada programa incluye: client, establishment, managers, vet, technique, protocol.

---

### A-08 · Detalle de programa

**Endpoint**: `GET /api/v1/programs/{id}`  
**Autenticación**: Requerida

Retorna la estructura completa del programa organizada por días:

```json
{
  "program": { ... },
  "days": [
    {
      "date": "2024-10-15",
      "alerts": [
        {
          "id": 1,
          "text": "Aplicar hormona X",
          "time": "08:00",
          "require_confirmation": true,
          "delivered_at": "2024-10-15T08:02:00Z",
          "confirmed_at": null
        }
      ]
    }
  ],
  "managers": [...],
  "group": { "animals": [...] }
}
```

Las alertas que el usuario no tiene permitido ver se filtran según su rol.

---

### A-09 · Crear programa

**Endpoint**: `POST /api/v1/programs`  
**Autenticación**: Requerida (solo roles vet)

**Request**
```json
{
  "client_id": 1,
  "establishment_id": 2,
  "technique_id": 3,
  "protocol_id": 4,
  "target_date": "2024-11-01",
  "managers": [5, 6],
  "comments": "Observaciones opcionales",
  "group_name": "Grupo 1",
  "animals_rp": "1234, 5678, 9012"
}
```

Disparadores al guardar:
1. `CreateProgramAction` crea el registro de programa
2. `UpsertProgramAlertsAction` genera las alertas desde el protocolo seleccionado
3. `ProgramCreatedNotification` notifica a todos los managers vía WhatsApp/SMS/Email

---

### A-10 · Confirmar alerta

**Endpoint**: `POST /api/v1/tasks/{alert_id}/complete`  
**Autenticación**: Requerida

Marca una alerta como confirmada por el usuario. Setea `confirmed_at = now()`. Usado cuando una alerta tiene `require_confirmation = true`.

---

### A-11 · Lista de planes de salud

**Endpoint**: `GET /api/v1/health_plans`  
**Autenticación**: Requerida

Planes de salud disponibles para el usuario autenticado (filtrados por vet o client según rol).

---

### A-12 · Detalle de plan de salud

**Endpoint**: `GET /api/v1/health_plans/{id}`  
**Autenticación**: Requerida

Retorna actividades del plan agrupadas por mes (enero = 1 … diciembre = 12):

```json
{
  "health_plan": { ... },
  "months": {
    "1": ["Vacunación", "Desparasitación"],
    "3": ["Control clínico"],
    "6": ["Vacunación"],
    ...
  }
}
```

---

### A-13 · Agenda

**Endpoint**: `GET /api/v1/agenda`  
**Autenticación**: Requerida (solo roles vet)

Lista de eventos de agenda del veterinario. Incluye cliente asociado.

---

### A-14 · Crear evento de agenda

**Endpoint**: `POST /api/v1/agenda`  
**Autenticación**: Requerida (solo roles vet)

**Request**
```json
{
  "name": "Visita campo Pérez",
  "client_id": 1,
  "event_type": "Visita",
  "date": "2024-11-05",
  "time": "09:00",
  "days": 1,
  "alert_time": "08:00"
}
```

Si `days` está presente, crea automáticamente una alerta de recordatorio `days` días antes del evento a la hora `alert_time`.

---

## 7. Pantallas — Panel Vet

Panel multi-tenant accesible en `/vet`. Cada veterinaria accede a su propio espacio identificado por `{slug}`. El middleware `RedirectIfNotCurrentVet` y el sistema de tenant de Filament aseguran el aislamiento de datos.

---

### V-01 · Login del panel Vet

**URL**: `/vet/login`  
**Rol**: Anónimo  
**Componente**: Filament Auth (CustomLogin)

**Descripción**  
Formulario de inicio de sesión para veterinarios y asistentes.

**Campos**

| Campo | Tipo | Descripción |
|---|---|---|
| Email / Usuario | email | Acepta email o username (CUIT) |
| Contraseña | password | — |
| Recordarme | checkbox | Sesión persistente |

**Comportamiento**
- Login correcto → redirige a `/vet/{slug}/`
- Si `must_change_password = true` → redirige a cambio de contraseña antes de continuar
- Si el vet no está validado (`validated_at IS NULL`) → acceso denegado con mensaje informativo
- El middleware `PasswordUpdatedMiddleware` intercepta la sesión hasta que se cambie la contraseña inicial

---

### V-02 · Dashboard

**URL**: `/vet/{slug}/`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Página de inicio del panel. Muestra widgets con resumen del estado de la cuenta.

**Widgets disponibles**

| Widget | Contenido |
|---|---|
| `ProgramTypeWidget` | Contador de programas activos agrupados por tipo de técnica (MOET, FIV, IATF) |
| `ActivePrograms` | Total de programas en estado `in_progress` |
| `Tutorials` | Accesos rápidos a los tutoriales cargados en el sistema |

---

### V-03 · Lista de programas

**URL**: `/vet/{slug}/programs`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Tabla con todos los programas reproductivos de la veterinaria.

**Columnas de la tabla**

| Columna | Descripción |
|---|---|
| Nombre | Nombre generado del programa (combinación de técnica + cliente) |
| Cliente | Nombre del cliente |
| Estado | Badge de color: `pending` (gris), `in_progress` (azul), `completed` (verde), `cancelled` (rojo) |
| Fecha creación | Ordenamiento descendente por defecto |

**Filtros disponibles**

| Filtro | Tipo | Descripción |
|---|---|---|
| Cliente | Select buscable | Filtra por cliente |
| Técnica | Select | Filtra por técnica (relación via protocolo) |
| Protocolo | Select buscable | Filtra por protocolo |
| Mostrar cancelados | Toggle ternario | Por defecto oculta cancelados |

**Acciones por fila**

| Acción | Visible cuando | Descripción |
|---|---|---|
| Ver | Siempre | Abre la vista detalle |
| Editar | Estado ≠ `cancelled` | Abre formulario de edición |
| Cancelar | Estado ≠ `cancelled` | Modal de confirmación; cambia estado a `cancelled` |

**Acción de cabecera**: Botón "Nuevo programa" → `/programs/create`

---

### V-04 · Crear programa

**URL**: `/vet/{slug}/programs/create`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Formulario de alta de programa reproductivo. Todos los selectores son reactivos: la selección de un campo carga dinámicamente las opciones del siguiente.

**Sección 1 — Datos del cliente**

| Campo | Tipo | Obligatorio | Comportamiento |
|---|---|---|---|
| Cliente | Select buscable | Sí | Al cambiar: resetea todos los campos dependientes |
| Establecimiento | Select | Sí | Cargado desde el cliente seleccionado |
| Gestores (vet) | Checkbox list | No | Usuarios de la veterinaria disponibles |
| Gestores (cliente) | Checkbox list | Sí | Usuarios del cliente seleccionado |

**Sección 2 — Datos del programa**

| Campo | Tipo | Obligatorio | Comportamiento |
|---|---|---|---|
| Técnica | Select | Sí | Técnicas raíz (MOET, FIV, IATF). Al cambiar: carga sub-técnicas |
| Sub-técnica | Select | Sí | Hijos de la técnica seleccionada |
| Protocolo | Select | Sí | Protocolos de la sub-técnica (globales + propios del vet) |
| Fecha objetivo | DatePicker | Sí | Formato dd/mm/aaaa. A partir de esta fecha se calculan todas las alertas |

**Sección 3 — Grupo de donantes** *(condicional: solo para MOET/FIV)*

| Campo | Tipo | Descripción |
|---|---|---|
| Nombre del grupo | Select | "Grupo único", "Grupo 1", "Grupo 2", "Grupo 3" |
| RP de donantes | Texto | Números de Registro Provincial separados por coma |

**Sección 4 — Otros datos**

| Campo | Tipo | Descripción |
|---|---|---|
| Comentarios | Textarea | Observaciones libres del programa |

**Al guardar**
1. Se crea el registro en `programs`
2. Se copian las tareas del protocolo como `tasks` con fechas calculadas
3. Se copian las alertas del protocolo como `alerts` con fechas calculadas (`target_date ± days_offset`)
4. Se envía `ProgramCreatedNotification` a todos los gestores seleccionados

---

### V-05 · Ver programa (detalle)

**URL**: `/vet/{slug}/programs/{id}`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Vista de solo lectura de un programa. Muestra todos los datos y la lista de alertas planificadas.

**Datos mostrados**

| Dato | Descripción |
|---|---|
| Cliente / Establecimiento | Con enlace al cliente |
| Técnica / Protocolo | Nombre y color del protocolo |
| Fecha objetivo | Fecha central del programa |
| Estado | Badge de estado |
| Comentarios | Si los hay |
| Gestores | Lista de usuarios gestores con sus roles |
| Grupo de donantes | Lista de animales con RP (si aplica) |

**Sección de alertas**  
Tabla con todas las alertas del programa ordenadas por fecha:

| Columna | Descripción |
|---|---|
| Fecha | Fecha programada de envío |
| Hora | Hora programada |
| Mensaje | Texto de la alerta |
| Destinatarios | Nombres de usuarios; en rojo si está en lista negra (opt-out) |
| Enviada | Fecha/hora de envío efectivo o "Pendiente" |

**Acciones disponibles**
- Editar → `/programs/{id}/edit`
- Descargar PDF → `/programs/{id}/get_file` (URL firmada)
- Cancelar programa (si no está cancelado)

---

### V-06 · Editar programa

**URL**: `/vet/{slug}/programs/{id}/edit`  
**Rol**: VET_VET, VET_ASSISTANT  
**Restricción**: Solo si estado ≠ `cancelled`

**Descripción**  
Formulario de edición. Los campos de técnica, sub-técnica y cliente están **deshabilitados** (no se puede cambiar el tipo de programa una vez creado). Se pueden modificar: protocolo, fecha objetivo, establecimiento, gestores y comentarios.

Al guardar se ejecuta `UpdateProgramAction` que recalcula todas las alertas.

---

### V-07 · Lista de protocolos

**URL**: `/vet/{slug}/protocols`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Lista de protocolos propios de la veterinaria. Los protocolos globales (sin `vet_id`) no aparecen aquí.

**Columnas**

| Columna | Descripción |
|---|---|
| Nombre | Nombre del protocolo (buscable) |
| Técnica | Técnica asociada (carga lazy) |

**Acciones por fila**
- Editar
- Replicar (crea una copia del protocolo con sus tareas y alertas)

---

### V-08 · Crear / Editar protocolo

**URL**: `/vet/{slug}/protocols/create` y `/protocols/{id}/edit`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Formulario para definir un protocolo reutilizable. Un protocolo es una plantilla de tareas y alertas que se aplica cada vez que se crea un programa con esa técnica.

**Campos generales**

| Campo | Tipo | Descripción |
|---|---|---|
| Técnica asociada | Solo lectura (placeholder) | Muestra la técnica padre |
| Nombre | Texto | Nombre identificador del protocolo |
| Color | Select | Amarillo, gris o celeste (se muestra en la lista de programas) |

**Sección de alertas** *(componente personalizado `Alerts::make()`)*

Tabla editable donde se definen las alertas plantilla. Cada fila representa una alerta:

| Campo | Tipo | Descripción |
|---|---|---|
| Texto | Textarea | Mensaje que recibirán los destinatarios |
| Días | Número | Cantidad de días de offset desde la fecha objetivo |
| Antes / Después | Select | Si el offset es antes (`Before`) o después (`After`) de la fecha objetivo |
| Hora | TimePicker | Hora de envío |
| Roles destinatarios | Checkbox | Qué roles reciben esta alerta (Vet, Cliente, etc.) |
| Requiere confirmación | Toggle | Si el destinatario debe confirmar la alerta en la app |

---

### V-09 · Lista de clientes

**URL**: `/vet/{slug}/clients`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Tabla de clientes (productores) asociados a la veterinaria.

**Columnas**: Nombre, CUIT/CUIL

**Acciones por fila**: Editar, Eliminar

**Acciones de cabecera**
- Nuevo cliente
- Importar (enlace a `/imports/create`)

**Acciones masivas**: Eliminar seleccionados

---

### V-10 · Crear cliente

**URL**: `/vet/{slug}/clients/create`  
**Rol**: VET_VET, VET_ASSISTANT

Formulario simple de alta de cliente.

| Campo | Tipo | Obligatorio |
|---|---|---|
| Nombre / Razón social | Texto | Sí |
| CUIT / CUIL | Texto | Sí (único) |
| País | Select | Sí (solo Argentina) |
| Provincia | Select | No |
| Ciudad | Texto | No |
| Dirección | Texto | No |
| Teléfono | Texto | No |
| Email | Email | No |

---

### V-11 · Editar cliente (ficha completa)

**URL**: `/vet/{slug}/clients/{id}/edit`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Ficha completa del cliente con tres pestañas adicionales de gestión (Relation Managers).

**Pestaña principal — Datos del cliente**  
Mismos campos que Crear cliente.

---

**Sub-pestaña: Establecimientos**

Lista de establecimientos del cliente con tabla:

| Columna | Descripción |
|---|---|
| Nombre | Nombre del campo / establecimiento |
| Ciudad | Ciudad de ubicación |
| Latitud | Coordenada GPS |
| Longitud | Coordenada GPS (columna `length` en BD) |

Formulario de alta/edición:
- Nombre (obligatorio)
- Ciudad (obligatorio)
- Latitud y longitud (numérico, opcional)
- Vista de mapa con la ubicación (si hay coordenadas)

---

**Sub-pestaña: Usuarios del cliente**

Lista de usuarios del portal del cliente:

| Columna | Descripción |
|---|---|
| Nombre | Nombre completo |
| Teléfono | Teléfono formateado |
| Rol | CLIENT_MANAGER o CLIENT_ASSISTANT (con etiqueta legible) |

Formulario de alta:
- Rol: `client_manager` o `client_assistant`
- Nombre, usuario (CUIT), contraseña (solo en alta)
- Código de área WhatsApp (sin 0) + número (sin 15)

Al crear usuario se envía `UserRegisterNotification` con la contraseña temporal por WhatsApp/Email.

---

**Sub-pestaña: Planes de Salud**

Lista de planes de salud del cliente con nombre, establecimiento, año y categoría.

**Dos formas de crear un plan:**

**Opción A — Crear desde cero**  
Formulario completo con selección de actividades y meses.

**Opción B — Crear desde plantilla**  
Selector de plantilla global; copia actividades y meses de la plantilla seleccionada.

En ambos casos, al guardar se generan automáticamente alertas 7 días antes del primer día de cada mes que tenga actividades programadas.

Formulario (dentro del modal):
- Nombre del plan
- Año
- Categoría (bovinos, porcinos, etc.)
- Establecimiento (del cliente)
- Actividades por mes: componente personalizado donde se seleccionan los meses para cada actividad

---

### V-12 · Lista de planes de salud

**URL**: `/vet/{slug}/health-plans`  
**Rol**: VET_VET, VET_ASSISTANT

Tabla con todos los planes de salud de la veterinaria.

**Columnas**: Nombre, Cliente, Establecimiento, Año, Categoría

---

### V-13 · Crear / Editar plan de salud

**URL**: `/vet/{slug}/health-plans/create` y `/health-plans/{id}/edit`  
**Rol**: VET_VET, VET_ASSISTANT

Formulario para crear o editar un plan directamente (sin pasar por la ficha del cliente).

**Campos**

| Campo | Tipo | Obligatorio |
|---|---|---|
| Nombre | Texto | Sí |
| Año | Select | Sí (año actual o siguiente) |
| Categoría | Select | Sí |
| Cliente | Select reactivo | Sí (carga establecimientos) |
| Establecimiento | Select | Sí |
| Actividades y meses | Componente personalizado | Sí |

**Sección de alertas**: Visualización de las alertas generadas (solo lectura).

---

### V-14 · Lista de eventos (Agenda)

**URL**: `/vet/{slug}/agendas`  
**Rol**: VET_VET, VET_ASSISTANT

Tabla de eventos de agenda de la veterinaria.

**Acciones por fila**: Editar, Eliminar  
**Acción de cabecera**: Nuevo evento

---

### V-15 · Crear / Editar evento

**URL**: `/vet/{slug}/agendas/create` y `/agendas/{id}/edit`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Formulario de alta de evento con recordatorio opcional.

**Sección 1 — Datos del evento**

| Campo | Tipo | Obligatorio |
|---|---|---|
| Título | Texto | Sí |
| Cliente | Select buscable y reactivo | Sí |
| Tipo de evento | Select | Sí |
| Fecha | DatePicker (mín: hoy) | Sí |
| Hora | TimePicker | Sí |

**Tipos de evento disponibles**: Tacto, Visita, Control sanitario, Cumpleaños, Otros

**Sección 2 — Recordatorio**

| Campo | Tipo | Descripción |
|---|---|---|
| Días antes | Select | En el día, 1, 2, 3, 4, 5, 6 o 7 días antes |
| Hora del recordatorio | TimePicker | Hora a la que se enviará el aviso |

Si se configuran días y hora, se crea automáticamente una alerta en `alerts` para enviar el recordatorio a los usuarios del vet.

---

### V-16 · Lista de usuarios (de la vet)

**URL**: `/vet/{slug}/users`  
**Rol**: Solo VET_VET

**Descripción**  
Gestión de los usuarios internos de la veterinaria (vets y asistentes).

**Columnas**: Nombre, Email, Rol (con etiqueta legible)

**Acciones por fila**: Editar, Eliminar  
**Acción de cabecera**: Nuevo usuario

---

### V-17 · Crear / Editar usuario de la vet

**URL**: `/vet/{slug}/users/create` y `/users/{id}/edit`  
**Rol**: Solo VET_VET

| Campo | Tipo | Obligatorio |
|---|---|---|
| Nombre | Texto | Sí |
| Email | Email | Sí |
| Contraseña | Password | Solo en alta |
| Rol | Select | Sí (VET_VET o VET_ASSISTANT) |
| Código de área WhatsApp | Texto (sin 0) | Sí |
| Número WhatsApp | Texto (sin 15) | Sí |

El teléfono se guarda formateado como `54{area}{numero}`.

---

### V-18 · Importar clientes

**URL**: `/vet/{slug}/imports/create`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Sube un archivo Excel para importar clientes y establecimientos de forma masiva.

**Pasos**
1. Leer las consideraciones previas mostradas en pantalla
2. Descargar la plantilla de ejemplo (enlace a `/clients_export`)
3. Completar la plantilla con los datos de los clientes
4. Subir el archivo Excel
5. Guardar → se encola el job `ImportClients`

**Procesamiento asincrónico**: el archivo se procesa en background. El usuario puede seguir usando el sistema y revisar el resultado en la lista de importaciones.

---

### V-19 · Lista de importaciones

**URL**: `/vet/{slug}/imports`  
**Rol**: VET_VET, VET_ASSISTANT

Historial de importaciones realizadas.

**Columnas**

| Columna | Descripción |
|---|---|
| # | ID con prefijo |
| Fecha | Fecha de subida |
| Estado | PENDING (azul), ON_GOING (naranja), COMPLETED (verde), ERROR (rojo) |

**Acción**: Ver detalle de cada importación

---

### V-20 · Resultado de importación

**URL**: `/vet/{slug}/imports/{id}`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Detalle del resultado de una importación. Si hubo errores, muestra la tabla de logs con número de fila y descripción del error.

**Si el estado es ERROR**: aparece un botón para crear un ticket de soporte pre-cargado con el detalle del error.

---

### V-21 · Lista de tickets de soporte

**URL**: `/vet/{slug}/support-messages`  
**Rol**: VET_VET, VET_ASSISTANT

Historial de mensajes de soporte enviados.

**Columnas**: Asunto, Fecha, Estado (Pendiente, En proceso, Resuelto)

**Acciones por fila**:
- Ver (siempre)
- Editar (solo si estado = `pending`)

---

### V-22 · Crear ticket de soporte

**URL**: `/vet/{slug}/support-messages/create`  
**Rol**: VET_VET, VET_ASSISTANT

| Campo | Tipo | Descripción |
|---|---|---|
| Asunto | Texto | Pre-cargado si viene desde un error de importación |
| Mensaje | Textarea (15 filas) | Pre-cargado con detalle del error si aplica |

---

### V-23 · Configuración de PDF

**URL**: `/vet/{slug}/program-settings`  
**Rol**: Solo VET_VET  
**Nota**: Sin ítem en el menú; acceso directo desde la URL

**Descripción**  
Permite personalizar el encabezado de los PDFs de programas.

| Campo | Tipo | Descripción |
|---|---|---|
| Logo | FileUpload | Imagen para el encabezado del PDF |
| Título | Texto | Título del PDF (ej: nombre de la veterinaria) |
| Subtítulo | Textarea | Texto secundario del encabezado |

Solo se puede crear una configuración por vet (se valida antes de mostrar el botón Crear).

---

### V-24 · Tutoriales

**URL**: `/vet/{slug}/tutorials`  
**Rol**: VET_VET, VET_ASSISTANT

**Descripción**  
Lista de videos/recursos de ayuda para el uso de la plataforma. No se pueden crear ni eliminar desde aquí (solo desde SuperAdmin).

**Vista de detalle** (`/tutorials/{id}`): muestra el título como h2 y un iframe con el video de YouTube. Incluye navegación "anterior" y "siguiente".

---

### V-25 · Editar perfil

**URL**: `/edit-profile` (redirige al panel Vet)  
**Rol**: Cualquier usuario autenticado del panel

Plugin de Filament para editar los datos personales del usuario logueado: nombre, email, foto de perfil, contraseña.

---

## 8. Pantallas — Panel SuperAdmin

Panel separado en `/superadmin`. Administra la plataforma a nivel global: veterinarias, técnicas, catálogos de salud y soporte.

---

### S-01 · Login SuperAdmin

**URL**: `/superadmin/login`  
**Rol**: Anónimo

Login estándar de Filament. Solo accesible para usuarios con rol `SUPERADMIN`.

---

### S-02 · Dashboard SuperAdmin

**URL**: `/superadmin/`  
**Rol**: SUPERADMIN

Widgets por defecto de Filament: resumen de cuenta y versión del framework.

---

### S-03 · Lista de veterinarias

**URL**: `/superadmin/vets`  
**Rol**: SUPERADMIN

**Descripción**  
Vista global de todas las organizaciones veterinarias registradas en la plataforma.

**Columnas**: Nombre, Dirección, Teléfono, CUIT, N° Matrícula, Fecha de validación

Todas las columnas son buscables.

**Acciones por fila**

| Acción | Visible cuando | Descripción |
|---|---|---|
| Editar | Siempre | Edita datos de la vet |
| Validar | `validated_at` es NULL | Confirma y registra la fecha de validación. Permite al vet acceder al panel |
| Administradores | Siempre | Sub-página de gestión de usuarios admin |

---

### S-04 · Crear / Editar veterinaria

**URL**: `/superadmin/vets/create` y `/vets/{id}/edit`  
**Rol**: SUPERADMIN

| Campo | Tipo | Obligatorio |
|---|---|---|
| Nombre | Texto | Sí |
| Dirección | Texto | Sí |
| Teléfono | Texto | Sí |
| CUIT | Texto | Sí |
| N° de matrícula | Texto | Sí |

---

### S-05 · Administradores de veterinaria

**URL**: `/superadmin/vets/{id}/manage-vet-admins`  
**Rol**: SUPERADMIN

Sub-página dentro de la edición de una vet. Permite gestionar los usuarios con rol VET_VET de esa organización.

---

### S-06 · Lista de técnicas

**URL**: `/superadmin/techniques`  
**Rol**: SUPERADMIN

**Descripción**  
Catálogo global de técnicas reproductivas disponibles para todos los vets.

**Columna**: Nombre (buscable)

---

### S-07 · Crear / Editar técnica

**URL**: `/superadmin/techniques/create` y `/techniques/{id}/edit`  
**Rol**: SUPERADMIN

| Campo | Tipo | Descripción |
|---|---|---|
| Nombre | Texto | Nombre de la técnica raíz (ej: MOET) |
| Nombre de fecha objetivo | Texto | Etiqueta de la fecha objetivo en la UI (ej: "Fecha de transferencia") |
| Sub-técnicas (Repeater) | Tabla editable | Lista de sub-técnicas con nombre y nombre de protocolos |

Cada sub-técnica incluye:
- Nombre (ej: MOET 2024)
- Nombre de protocolos (etiqueta del selector en la UI)

---

### S-08 · Categorías de planes de salud

**URL**: `/superadmin/health-plan-categories`  
**Rol**: SUPERADMIN

CRUD simple de categorías (ej: Bovinos, Porcinos, Ovinos).

| Campo | Obligatorio |
|---|---|
| Nombre | Sí |

---

### S-09 · Plantillas de planes de salud

**URL**: `/superadmin/health-plan-templates`  
**Rol**: SUPERADMIN

**Descripción**  
Templates globales que los vets pueden usar como base para crear planes de salud.

**Columnas**: Nombre, Categoría (ordenable)

**Formulario**

| Campo | Tipo | Obligatorio |
|---|---|---|
| Nombre | Texto | Sí |
| Categoría | Select | Sí |
| Actividades y meses | Componente personalizado | Sí |

El componente de actividades y meses permite seleccionar qué actividad se realiza en qué meses del año (de enero a diciembre).

---

### S-10 · Actividades de salud

**URL**: `/superadmin/health-activities`  
**Rol**: SUPERADMIN

CRUD del catálogo global de actividades (ej: Vacunación, Desparasitación, Control clínico, Control reproductivo).

| Campo | Obligatorio |
|---|---|
| Nombre | Sí |

---

### S-11 · Soporte (SuperAdmin)

**URL**: `/superadmin/support-messages`  
**Rol**: SUPERADMIN

**Descripción**  
Vista global de todos los tickets de soporte enviados por veterinarias. El SuperAdmin puede ver y responder mensajes.

**Columnas**: Asunto, Fecha, Estado, Veterinaria

**Acciones**: Ver, editar estado y responder.

---

## Apéndice A — Reglas de UI detectadas

| Regla | Pantalla afectada |
|---|---|
| Al cambiar el Cliente en Crear Programa se resetean todos los campos dependientes | V-04 |
| Los campos Técnica, Sub-técnica y Cliente están deshabilitados en Editar Programa | V-06 |
| El botón "Crear configuración PDF" no aparece si ya existe una | V-23 |
| Las técnicas se muestran agrupadas: raíz + hijos en Create Program | V-04 |
| Los protocolos globales (sin vet) aparecen en todos los selectores de todos los vets | V-04 |
| En la vista de alertas, los destinatarios en lista negra aparecen en rojo tachado | V-05 |
| Los usuarios `must_change_password=true` son bloqueados por middleware hasta cambiar contraseña | V-01 |
| Los vets no validados (`validated_at IS NULL`) no pueden iniciar sesión | V-01 |
| Solo VET_VET ve el ítem "Usuarios" en el menú | V-02 |

---

## Apéndice B — Componentes Filament personalizados

| Componente | Usado en | Descripción |
|---|---|---|
| `Forms\Components\Alerts` | Crear/Editar Protocolo | Tabla editable para definir alertas plantilla con offset, hora y roles |
| `Forms\Components\HealthPlanActivities` | Crear/Editar Plan de Salud | Grid de actividades × meses (12 columnas), permite marcar qué actividades van en qué meses |
| `Forms\Components\HealthPlanAlerts` | Ver Plan de Salud | Visualización de alertas generadas (solo lectura) |
| Vista `alerts_list.blade.php` | Ver Programa | Renderiza tabla de alertas con estado de entrega y opt-out |
| Vista `program.blade.php` | PDF | Template DomPDF para el PDF descargable del cronograma |

---

## Apéndice C — Notificaciones enviadas por evento

| Evento | Notificación | Canal |
|---|---|---|
| Alta de usuario | `UserRegisterNotification` | WhatsApp + Email |
| Programa creado | `ProgramCreatedNotification` | WhatsApp + SMS + Email |
| Programa actualizado | `ProgramUpdated` | WhatsApp + SMS + Email |
| Programa cancelado | `ProgramCanceledNotification` | WhatsApp + SMS + Email |
| Alerta programada | `ProgramTaskNotification` | WhatsApp + SMS + Email |
| Actividad del mes (plan de salud) | `HealthPlanMonthNotification` | WhatsApp + SMS + Email |
| Recordatorio de evento | `EventNotification` | WhatsApp + SMS + Email |
| WhatsApp "BAJA" recibido | Confirmación opt-out | WhatsApp |
| WhatsApp "ALTA" recibido | Confirmación opt-in | WhatsApp |
