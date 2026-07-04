# Inventario Técnico — SAV (Sistema de Alertas Veterinarias)

**Fecha de auditoría:** 2026-06-20  
**Auditor:** Software Architect Senior  
**Sistema:** SAV Backend — rama `develop`  
**Clasificación:** Confidencial

---

## 1. Arquitectura General

### 1.1 Stack Tecnológico

| Capa | Tecnología | Versión |
|---|---|---|
| Runtime | PHP | ^8.2 |
| Framework | Laravel | ^11.0 |
| Panel admin | Filament | ^3.2 |
| Autenticación API | Laravel Sanctum | ^4.0 |
| Roles y permisos | Spatie Laravel Permission | ^6.4 |
| Base de datos | MySQL | — |
| Frontend admin | Blade + Tailwind CSS | ^3.4 |
| Build tool | Vite | ^5.0 |
| Queue | Sync (default, configurable) | — |
| PDF | barryvdh/laravel-dompdf | ^2.2 |
| Excel | Maatwebsite/Excel | ^3.1 |
| SMS/WhatsApp | Twilio SDK | ^8.2 |
| WhatsApp Cloud | netflie/whatsapp-cloud-api | ^2.2 |
| Slugs | spatie/laravel-sluggable | ^3.5 |
| reCAPTCHA | josiasmontag/laravel-recaptchav3 | ^1.0 |
| Iconografía | blade-tabler-icons | ^2.3 |
| Avatares | Boring Avatars (custom provider) | — |
| Testing | PHPUnit | ^10.1 |
| Linter | Laravel Pint | ^1.9 |
| Dev server | Laravel Sail (Docker) | ^1.24 |
| Debug | Laravel DebugBar | ^3.14 |

### 1.2 Patrones Arquitectónicos

- **MVC** con Laravel convencional para la API REST
- **Action Pattern**: lógica de dominio extraída a clases `Action` estáticas (`app/Actions/`)
- **Multi-tenancy via Filament**: el modelo `Vet` actúa como tenant; el panel `/vet/{slug}` está scopeado por veterinario
- **Repository-less**: acceso directo a Eloquent desde controladores y actions (sin capa repository)
- **Notification + Channel Pattern**: notificaciones enviadas a través de canales customizados (Twilio)
- **Job-based scheduling**: tareas periódicas implementadas como `ShouldQueue` + Laravel Scheduler
- **Form Request**: validaciones centralizadas en clases Request (parcialmente)

### 1.3 Flujo de Requests

```
[Mobile App]
    │ POST /api/v1/login  →  AuthController@login  →  Sanctum token
    │ GET  /api/v1/*      →  middleware: auth:sanctum  →  API Controllers  →  JSON
    └─────────────────────────────────────────────────────────────────────────────

[Navegador — Admin]
    │ GET /superadmin/*  →  SuperadminPanelProvider  →  app/Filament/Resources/
    │ GET /vet/{slug}/*  →  VetPanelProvider (tenant: Vet)  →  app/Filament/Vet/Resources/
    └─────────────────────────────────────────────────────────────────────────────

[WhatsApp/Twilio webhook]
    └ POST /whatsapp-webhook  →  WhatsAppWebhookController / WhatsAppController@receive
```

### 1.4 Integraciones Externas

| Servicio | Propósito | Variables de entorno |
|---|---|---|
| Twilio | Envío de SMS y WhatsApp templated messages | `TWILIO_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_PHONE_NUMBER` |
| WhatsApp Cloud API | Canal alternativo de WhatsApp | `WHATSAPP_*` |
| MySQL | Base de datos principal | `DB_*` |
| SMTP / Mailpit | Correo transaccional (dev: Mailpit) | `MAIL_*` |
| AWS S3 | Almacenamiento de archivos (opcional) | `AWS_*` |
| Pusher | Broadcasting (configurado, no activo) | `PUSHER_*` |
| Google reCAPTCHA v3 | Registro público de veterinarios | `RECAPTCHA_*` |

---

## 2. Estructura del Proyecto

```
sav-back-develop/
├── app/
│   ├── Actions/                 # Lógica de dominio (Action Pattern)
│   │   ├── HealthPlans/         # CreateHealthPlanAction, UpdateHealthPlanAction
│   │   └── Programs/            # CreateProgramAction, UpdateProgramAction,
│   │                            # CancelProgramAction, UpsertProgramAlertsAction
│   ├── Channels/                # Canales de notificación custom (Twilio)
│   ├── Enum/                    # Enums PHP 8.1+ (RolesEnum, ImportStatusEnum)
│   ├── Filament/
│   │   ├── Auth/                # CustomLogin (sobreescribe login Filament)
│   │   ├── Pages/               # EditProfile, RegisterVet (tenancy)
│   │   ├── Resources/           # Panel SuperAdmin: Vet, Technique, HealthActivity,
│   │   │                        # HealthPlanCategory, HealthPlanTemplate,
│   │   │                        # SupportMessage, Tutorial, Vaccine
│   │   └── Vet/
│   │       ├── Resources/       # Panel Vet: Program, Client, HealthPlan, Protocol,
│   │       │                    # Agenda, Invoice, Import, Users, ProgramSetting,
│   │       │                    # SupportMessage, Tutorial
│   │       └── Widgets/         # ActivePrograms, ProgramTypeWidget, Tutorials
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/             # 9 controladores REST (AuthController, ProgramsController…)
│   │   │   ├── Auth/            # RegisterController (registro público vets)
│   │   │   ├── Programs/        # GetFileController (descarga PDF)
│   │   │   └── WhatsApp/        # WhatsAppWebhookController
│   │   ├── Middleware/          # 15 middlewares (custom: ApplyTenantTheme,
│   │   │                        # PasswordUpdatedMiddleware, RedirectIfNotVet…)
│   │   └── Requests/            # Form Requests de validación
│   ├── Imports/ Exports/        # Maatwebsite/Excel handlers
│   ├── Jobs/                    # SendAlertsNotifications, ImportClients
│   ├── Models/                  # 29 modelos Eloquent
│   ├── Notifications/           # Clases de notificación (Twilio)
│   ├── Policies/                # Autorización por modelo
│   ├── Providers/
│   │   ├── AppServiceProvider.php
│   │   ├── AuthServiceProvider.php
│   │   ├── BoringAvatarsProvider.php
│   │   ├── EventServiceProvider.php
│   │   └── Filament/
│   │       ├── SuperadminPanelProvider.php   # Panel /superadmin (default)
│   │       └── VetPanelProvider.php          # Panel /vet/{slug} (multi-tenant)
│   └── Traits/                  # BelongsToVetTrait, HasAlertsTrait, NumberCanReceiveMessages
├── database/
│   ├── migrations/              # 56 migraciones (ene 2024 – oct 2024)
│   ├── seeders/
│   └── factories/
├── routes/
│   ├── api.php                  # /api/v1/* con prefix group
│   ├── web.php                  # Front público + webhooks + exports + Filament
│   └── console.php              # Scheduler: SendAlerts (everyMinute), ImportClients (everyTenMinutes)
├── resources/views/             # Blade: front/, exports/, filament/ overrides
├── config/                      # auth, filament, permission, filesystems…
└── storage/
    └── app/public/vets/         # Logos por veterinario
```

---

## 3. Base de Datos

### 3.1 Diagrama Entidad-Relación (narrativo)

```
Vet ──< Client (via client_vet pivot, N:M)
Vet ──< User
Vet ──< Protocol
Vet ──< Program
Vet ──< Establishment
Vet ──< HealthPlan
Vet ──< Event
Vet ──< Invoice
Vet ──< Import
Vet ──< ProgramSetting

Client ──< Establishment
Client ──< User
Establishment ──< AnimalsGroup ──< Animal

Technique ──< Protocol ──< ProtocolTask
                       ──< ProtocolAlert
Technique ──< Technique (parent_id, auto-referencia)

Program >── Vet, Client, Establishment, Technique, Protocol
Program >── AnimalsGroup (opcional)
Program ──< Tasks
Program ──< Alert (via model_id / model_class polimórfico)
Program N:M User (via program_manager)

HealthPlanCategory ──< HealthPlanTemplate N:M HealthActivity
HealthPlanCategory ──< HealthPlan
HealthPlan >── Vet, Client, Establishment
HealthPlan N:M HealthActivity (via health_plan_activity)
HealthPlan ──< Alert (polimórfico)

Event >── Vet, Client
Event ──< Alert (polimórfico)

Alert N:M User (via alert_user)

Invoice >── Vet
Invoice ──< InvoiceLine

Import >── Vet
Import ──< ImportLog

SupportMessage >── User, Vet
```

### 3.2 Inventario de Tablas

| Tabla | Propósito | Columnas clave |
|---|---|---|
| `vets` | Veterinarios / tenants | id, name, cuit, address, phone, registration_number, college, university, vet_id (DNI), slug |
| `users` | Usuarios del sistema | id, name, username (unique), email, phone, password, vet_id FK, client_id FK, password_changed_at |
| `clients` | Clientes/establecimientos ganaderos | id, name, cuit_cuil (unique), address, city, postal_code, state, country, phone_1, phone_2, email, soft_deletes |
| `client_vet` | Pivot Vet ↔ Client | client_id, vet_id |
| `establishments` | Campos / establecimientos | id, client_id FK, name, city, postal_code, latitude, length ⚠️ |
| `animals_groups` | Lotes de animales | id, name, establishment_id FK |
| `animals` | Animales individuales | id, rp_donor, animals_group_id FK |
| `techniques` | Técnicas reproductivas (ej. IATF) | id, name, target_date_name, type, parent_id (auto-ref), protocols_name |
| `protocols` | Protocolos hormonales | id, name, color, vet_id FK nullable, technique_id FK, soft_deletes |
| `protocols_tasks` | Tareas de un protocolo | id, protocol_id FK, description, days_offset, time_of_day (Before/After), time, important |
| `protocol_alerts` | Alertas de un protocolo | id, protocol_id FK, text (JSON), days_offset, time_of_day, time, roles (JSON), require_confirmation, confirmed_at |
| `programs` | Instancias de protocolo en campo | id, vet_id FK, client_id FK, establishment_id FK, group_id FK, technique_id FK, protocol_id FK, target_date, state, comments |
| `program_manager` | Pivot Program ↔ User | program_id, user_id |
| `tasks` | Tareas generadas por programa | id, program_id FK, description, date, time, important, completed_at |
| `alerts` | Alertas a enviar (polimórficas) | id, model_id, model_class, notification_class, text, send_at, delivered_at, require_confirmation, confirmed_at |
| `alert_user` | Pivot Alert ↔ User (recipients) | alert_id, user_id |
| `health_plan_categories` | Categorías de plan sanitario | id, name, soft_deletes |
| `health_plan_templates` | Plantillas de plan sanitario | id, name, health_plan_category_id FK, soft_deletes |
| `health_activities` | Actividades sanitarias (ej. vacunación) | id, name, soft_deletes |
| `health_plan_template_activity` | Pivot template ↔ activity | id, health_plan_template_id, health_activity_id, months (string) |
| `health_plans` | Planes sanitarios anuales | id, client_id FK, establishment_id FK, name, year, health_plan_category_id FK, vet_id FK, soft_deletes |
| `health_plan_activity` | Pivot plan ↔ activity | id, health_plan_id, health_activity_id, months (string) |
| `events` | Eventos en agenda del vet | id, name, event_type, date, time, vet_id FK, client_id FK nullable |
| `invoices` | Facturas del veterinario | id, code, date, due_date, vet_id FK, status, type (default: invoice), currency_code (ARS), amount |
| `invoice_lines` | Líneas de factura | id, invoice_id FK, description, price, tax, discount, total |
| `imports` | Importaciones de clientes (Excel) | id, vet_id FK, file_path, status (enum), completed_at |
| `import_logs` | Log de errores de importación | id, import_id FK, line_number, error |
| `support_messages` | Mensajes de soporte | id, user_id FK, vet_id FK, subject, status, message |
| `program_settings` | Configuración visual por vet | id, vet_id FK, logo, title, subtitle |
| `tutorials` | Videos/tutoriales del sistema | id, title, url, order |
| `opt_out_numbers` | Números en lista de bajas WhatsApp | id, phone |
| `contacts` | (Tabla vacía, sin uso detectado) | — |
| `roles`, `permissions`, `*_has_*` | Spatie RBAC (5 tablas) | Standard Spatie |
| `personal_access_tokens` | Sanctum tokens | Standard Laravel |
| `password_reset_tokens` | Reset de contraseñas | Standard Laravel |
| `failed_jobs` | Jobs fallidos | Standard Laravel |

### 3.3 Reglas de Negocio Inferidas

- Un **Vet** puede gestionar múltiples **Clients**, y un Client puede pertenecer a múltiples Vets (relación N:M).
- Un **Program** es la instancia concreta de aplicar un **Protocol** (de una **Technique**) a un **Establishment** en una fecha objetivo (`target_date`).
- Las **Alerts** del programa se calculan automáticamente a partir de los `ProtocolAlert` del protocolo, con offsets en días Before/After respecto a `target_date`.
- Las alertas ya pasadas (anteriores a hoy) **no se crean** al generar el programa (`UpsertProgramAlertsAction`).
- Los **recipients** de cada alerta se determinan por los roles definidos en `protocol_alerts.roles` (JSON).
- El campo `months` en `health_plan_activity` es un string serializado que indica en qué meses del año aplica cada actividad.
- Un número de teléfono en `opt_out_numbers` no recibirá mensajes de WhatsApp.
- `password_changed_at = null` obliga al usuario a cambiar contraseña antes de continuar (middleware `PasswordUpdatedMiddleware`).

---

## 4. API REST

**Base URL:** `/api/v1`  
**Autenticación:** Bearer token (Laravel Sanctum)  
**Content-Type:** `application/json`

### 4.1 Endpoints Públicos (sin autenticación)

| Método | URI | Controlador | Descripción |
|---|---|---|---|
| POST | `/api/v1/login` | `AuthController@login` | Login por username + password + device_name → retorna token |
| POST | `/api/v1/whatsapp-webhook` | `WhatsAppController@receive` | Webhook Twilio: maneja BAJA/ALTA de opt-out |

### 4.2 Endpoints Autenticados (Bearer token requerido)

| Método | URI | Controlador | Descripción |
|---|---|---|---|
| GET | `/api/v1/user` | Inline | Usuario autenticado |
| POST | `/api/v1/logout` | `AuthController@logout` | Elimina todos los tokens del usuario |
| POST | `/api/v1/change-password` | `AuthController@changePassword` | Cambio de contraseña |
| GET | `/api/v1/techniques` | `TechniquesController@techniques` | Listado de técnicas (query param: `type`) |
| GET | `/api/v1/techniques/protocols` | `TechniquesController@protocols` | Protocolos del vet autenticado |
| GET | `/api/v1/techniques/{technique}/protocols` | `TechniquesController@protocols` | Protocolos de una técnica específica |
| GET | `/api/v1/clients` | `ClientsController@clients` | Clientes del vet autenticado |
| GET | `/api/v1/clients/{client}/establishments` | `ClientsController@establishments` | Establecimientos de un cliente |
| GET | `/api/v1/clients/{client}/managers` | `ClientsController@managers` | Usuarios managers de un cliente |
| GET | `/api/v1/clients/{establishment}/groups` | `ClientsController@groups` | Grupos de animales (con animales anidados) |
| GET | `/api/v1/programs` | `ProgramsController@index` | Programas (filtra por vet_id o client_id) |
| GET | `/api/v1/programs/{program}` | `ProgramsController@show` | Detalle del programa con días/alertas agrupados |
| POST | `/api/v1/programs` | `ProgramsController@store` | Crear programa + alertas automáticas |
| PUT | `/api/v1/programs/{program}` | `ProgramsController@update` | Actualizar programa y recalcular alertas |
| POST | `/api/v1/programs/{program}/cancel` | `ProgramsController@cancel` | Cancelar programa y sus alertas |
| POST | `/api/v1/tasks/{alert}/complete` | `ProgramsController@confirmAlert` | Confirmar/completar una alerta |
| GET | `/api/v1/health_plans` | `HealthPlanController@index` | Planes sanitarios por rol |
| GET | `/api/v1/health_plans/{healthPlan}` | `HealthPlanController@show` | Detalle con actividades por mes |
| GET | `/api/v1/agenda` | `AgendaController@index` | Eventos de agenda del vet |
| GET | `/api/v1/agenda/{event}` | `AgendaController@show` | Detalle de evento |
| POST | `/api/v1/agenda` | `AgendaController@store` | Crear evento con alerta opcional |
| PUT | `/api/v1/agenda/{event}` | `AgendaController@update` | Actualizar evento y su alerta |
| GET | `/api/v1/vets/users` | `VetsController@users` | Usuarios del vet autenticado |

### 4.3 Estructura de Respuesta — Login

```json
{
  "token": "1|abc...",
  "user": {
    "name": "string",
    "email": "string",
    "role": "vet | vet-assistant | client-owner | ...",
    "vet": { "name": "string" },
    "client": { "name": "string" },
    "client_id": 1,
    "vet_id": 1,
    "client_vet": { "name": "string" }
  }
}
```

### 4.4 Parámetros de Validación — `POST /programs`

```json
{
  "client_id":        "required|integer|exists:clients,id",
  "establishment_id": "required|exists:establishments,id",
  "technique_id":     "required|exists:techniques,id",
  "protocol_id":      "required|exists:protocols,id",
  "target_date":      "required|date",
  "state":            "required|in:Pending,Completed",
  "comments":         "nullable|string",
  "managers":         "required",
  "group_name":       "sometimes|string",
  "animals_rp":       "sometimes|string"
}
```

### 4.5 Rutas Web Públicas

| Método | URI | Controlador | Descripción |
|---|---|---|---|
| GET | `/` | `FrontController@index` | Landing page pública |
| GET | `/register` | `FrontController@register` | Formulario de registro de vet |
| POST | `/register` | `RegisterController@register` | Procesa registro con reCAPTCHA |
| GET | `/terms-conditions` | `FrontController@termsAndConditions` | Términos y condiciones |
| GET | `/privacy-policy` | `FrontController@privacyPolicy` | Política de privacidad |
| POST | `/whatsapp-webhook` | `WhatsAppWebhookController@index` | Webhook web de WhatsApp |
| GET | `/protocol_task_export` | `ProtocolTasksExportController@export` | Descarga plantilla Excel de tareas |
| GET | `/clients_export` | `ClientsExportController@export` | Descarga plantilla Excel de clientes |
| GET | `/programs/{program}/get_file` | `GetFileController@getFile` | Descarga PDF del programa |

---

## 5. Procesos Batch y Colas

### 5.1 Scheduler (`routes/console.php`)

| Job | Frecuencia | Propósito |
|---|---|---|
| `SendAlertsNotifications` | `everyMinute()` | Consulta alertas con `send_at <= now()` y `delivered_at = null`, envía notificaciones, marca `delivered_at` |
| `ImportClients` | `everyTenMinutes()` | Procesa imports con status `PENDING`, ejecuta `ClientsImport`, actualiza status |

### 5.2 Jobs

**`SendAlertsNotifications`** (`ShouldQueue`)

```
handle():
  alertsToSend = Alert::where('send_at', '<=', now())
                       ->whereNull('delivered_at')
                       ->get()

  foreach alertsToSend as alert:
    foreach alert.recipients as recipient:
      recipient.notify(new $alert->notification_class($alert))
      alert.delivered_at = now()   ← ⚠️ Bug: dentro del loop de recipients
      alert.save()
```

**`ImportClients`** (`ShouldQueue`)

```
handle():
  import = Import::where('status', 'PENDING')->first()
  import.status = 'ON_GOING'
  import.save()

  ClientsImport::import(import.file_path)
    → Crea/restaura clientes, establecimientos y usuarios
    → Crea ImportLog por cada error de fila

  import.status = 'COMPLETED' | 'ERROR'
  import.completed_at = now()
```

### 5.3 Configuración de Colas

- `QUEUE_CONNECTION=sync` por defecto (síncrono, bloquea la request)
- Para producción se recomienda `database` o `redis`
- No hay configuración de reintentos (`tries`, `backoff`) en los jobs

### 5.4 Notificaciones y Plantillas Twilio

| Clase Notificación | Content SID | Trigger |
|---|---|---|
| `ProgramCreatedNotification` | `HXf21519b33fc95a762315e0a9289c101c` | Creación de programa |
| `ProgramCanceledNotification` | `HXbf82a4e83175759cf88d9ab5252a7cbd` | Cancelación de programa |
| `EventNotification` | `HX8b44cd3f5741c6780d39d396e6d7dec3` | Recordatorio de evento de agenda |
| `HealthPlanMonthNotification` | `HXb734a385bc5cdc6bbe1dfac9dee85fb7` | Recordatorio mensual de plan sanitario |
| `ProgramTaskNotification` | 5 SIDs (según N° de mensajes) | Alerta de tarea de protocolo |

---

## 6. Seguridad

### 6.1 Roles del Sistema (`app/Enum/RolesEnum.php`)

| Constante | Valor BD | Contexto |
|---|---|---|
| `VET_VET` | `vet` | Veterinario principal — acceso total al panel |
| `VET_ASSISTANT` | `vet-assistant` | Asistente del veterinario |
| `VET_ADMINISTRATIVE` | `vet-administrative` | Administrativo del veterinario |
| `CLIENT_MANAGER` | `client-manager` | Encargado del establecimiento cliente |
| `CLIENT_ADMINISTRATIVE` | `client-administrative` | Administrativo del cliente |
| `CLIENT_OWNER` | `client-owner` | Dueño del establecimiento |

### 6.2 Autenticación

- **API**: Laravel Sanctum (token Bearer). Token se crea al login por `device_name`, se elimina al logout.
- **Panel Admin (Vet)**: Sesión web Filament con guard `web`, con `email_verification`, `password_reset`.
- **Panel SuperAdmin**: Sesión web Filament con guard `web`, protegido por `RedirectIfNotSuperadmin`.
- **Registro de veterinarios**: Público, vía `/register`, protegido con reCAPTCHA v3.

### 6.3 Autorización

- **Filament Vet panel**: Multi-tenancy — cada vet solo ve sus datos. `GlobalScope` en modelos `Client` y `Protocol` filtra por `vet_id` del tenant activo (con chequeo `request()->is('api/*')` para excluir API).
- **API**: Lógica de autorización manual en controladores (no usa Policies). Ejemplo: `ProgramsController` chequea si el usuario tiene `$user->vet` o `$user->client` para filtrar datos.
- **`show` de programa**: Filtra alertas visibles por rol — VET_VET y VET_ASSISTANT ven todo; otros roles solo ven sus alertas asignadas.
- **Spatie Permission**: RBAC completo con roles y permisos; integrado en Filament via `althinect/filament-spatie-roles-permissions`.
- **Policies**: Existen en `app/Policies/` pero su uso no es consistente en toda la API.

### 6.4 Middlewares de Seguridad

| Middleware | Propósito |
|---|---|
| `RedirectIfNotVet` | Filament: rechaza si no tiene relación con Vet |
| `RedirectIfNotCurrentVet` | Filament: verifica que el slug del tenant coincide |
| `RedirectIfNotSuperadmin` | Filament: bloquea panel superadmin para no-superadmins |
| `PasswordUpdatedMiddleware` | Obliga cambio de contraseña si `password_changed_at = null` |
| `ApplyTenantTheme` | Aplica logo/colores del vet en el panel |
| `ForceHttps` | Redirige a HTTPS (en producción) |
| `ValidateSignature` | Valida URLs firmadas (usado en exportaciones) |

---

## 7. Paneles Filament

### 7.1 Panel SuperAdmin (`/superadmin`)

- **Proveedor**: `SuperadminPanelProvider` (panel `default`)
- **Recursos** en `app/Filament/Resources/`:

| Recurso | Descripción |
|---|---|
| `VetResource` | CRUD de veterinarios con `ManageVetAdmins` |
| `TechniqueResource` | Técnicas reproductivas con `ProtocolsRelationManager` |
| `HealthActivityResource` | Actividades sanitarias |
| `HealthPlanCategoryResource` | Categorías de plan sanitario |
| `HealthPlanTemplateResource` | Plantillas de planes sanitarios |
| `SupportMessageResource` | Mensajes de soporte |
| `TutorialResource` | Gestión de tutoriales |
| `VaccineResource` | Vacunas con `ProtocolsRelationManager` |

### 7.2 Panel Vet (`/vet/{slug}`)

- **Proveedor**: `VetPanelProvider` (multi-tenant, tenant = `Vet`)
- **Grupos de navegación**: Reproduction, Health, Agenda, Settings, Support
- **Recursos** en `app/Filament/Vet/Resources/`:

| Recurso | Descripción |
|---|---|
| `ProgramResource` | Gestión de programas con wizard form y generación de PDF |
| `ClientResource` | Clientes con RelationManagers (Establishments, Users, HealthPlans) |
| `ProtocolResource` | Protocolos con replicación (`ReplicateProtocol`) |
| `HealthPlanResource` | Planes sanitarios con matriz de actividades por mes |
| `AgendaResource` | Eventos de agenda con configuración de recordatorios |
| `InvoiceResource` | Facturación (lectura + registro) |
| `ImportResource` | Importación de clientes desde Excel |
| `UsersResource` | Usuarios del equipo del vet |
| `ProgramSettingResource` | Personalización visual (logo, título, subtítulo) |
| `SupportMessageResource` | Mensajes de soporte al usuario |
| `TutorialResource` | Vista de tutoriales |

- **Widgets**: `ActivePrograms`, `ProgramTypeWidget`, `Tutorials`

---

## 8. Riesgos Técnicos

### CRÍTICO

#### R-01 — Bypass de Autenticación en Login

**Archivo:** `app/Http/Controllers/Api/AuthController.php:26`  
**Severidad:** Crítica — Exposición total de la API

```php
// CÓDIGO ACTUAL (BUG):
if (!$user /*|| !Hash::check($request->password, $user->password)*/) {

// CÓDIGO CORRECTO:
if (!$user || !Hash::check($request->password, $user->password)) {
```

La verificación de contraseña está **comentada**. Cualquier usuario puede autenticarse proporcionando únicamente un `username` válido, sin importar la contraseña enviada. **En producción, la API no tiene autenticación real.**

---

### ALTO

#### R-02 — Queue Síncrona en Producción

El scheduler `SendAlertsNotifications` corre `everyMinute()`. Con `QUEUE_CONNECTION=sync`, cada dispatch es síncrono y bloqueante. Si hay muchas alertas o Twilio está lento, puede generar timeout del proceso. No hay configuración de `tries` ni `backoff` en los jobs.

#### R-03 — Inconsistencias en Migraciones (`down()`)

Varios métodos `down()` referencian tablas incorrectas:

| Migración | Error en `down()` | Tabla correcta |
|---|---|---|
| `create_protocols_table` | `dropIfExists('templates')` | `protocols` |
| `create_programs_table` | `dropIfExists('projects')` | `programs` |
| `create_animals_groups_table` | `dropIfExists('animal_groups')` | `animals_groups` |

Impide que `php artisan migrate:rollback` funcione correctamente.

#### R-04 — Marca `delivered_at` antes de confirmar todos los recipients

**Archivo:** `app/Jobs/SendAlertsNotifications.php:39`

```php
foreach ($alert->recipients as $recipient) {
    try {
        $recipient->notify(...);
        $alert->delivered_at = now(); // ← se marca al primer recipient exitoso
        $alert->save();
    } catch (\Exception $e) {
        continue; // ← recipients siguientes no se notifican si ya fue marcada
    }
}
```

Si el primer recipient es exitoso pero el segundo falla, `delivered_at` ya fue marcado y la alerta no volverá a procesarse. Los recipients restantes nunca reciben la notificación.

---

### MEDIO

#### R-05 — GlobalScope frágil para multi-tenancy en API

**Afecta:** `app/Models/Client.php` y `Protocol.php`

```php
// Dentro del GlobalScope:
if (request()->is('api/*')) return; // ← bypass por URL pattern
```

Este check evita que el scope de tenant se aplique en la API, pero no es robusto. Un cambio en el prefijo de rutas podría romper el aislamiento de datos entre veterinarios.

#### R-06 — Sin autorización formal en endpoints de la API (IDOR potencial)

Los controladores API no usan Policies de Laravel. La autorización se hace con comprobaciones manuales. `cancel`, `confirmAlert` y varios endpoints de solo lectura no validan si el recurso pertenece al usuario autenticado (posible Insecure Direct Object Reference).

#### R-07 — Webhook de WhatsApp sin validación de firma

`POST /whatsapp-webhook` y `POST /api/v1/whatsapp-webhook` son endpoints públicos que procesan mensajes entrantes sin verificar la firma HMAC de Twilio. Cualquiera puede enviar una request POST simulando un webhook para manipular la lista de opt-out.

#### R-08 — Columna mal nombrada en BD

En la migración de `establishments`, la columna de longitud geográfica se llama `length` en lugar de `longitude`. El modelo `Establishment` usa `fillable: ['latitude', 'length']`, creando inconsistencia semántica.

---

### BAJO

#### R-09 — Modelos `Contact` y `Places` vacíos

`app/Models/Contact.php` y `app/Models/Places.php` existen pero no tienen ninguna implementación. Son código muerto/legacy sin limpiar.

#### R-10 — Nombre inconsistente de modelo `Tasks`

El modelo se llama `Tasks` (plural) en lugar de `Task`, inconsistente con las convenciones de Laravel. Complica el uso de route model binding.

#### R-11 — `WhatsAppNotificationsChannel` sin implementar

`app/Channels/WhatsAppNotificationsChannel.php` referenciado en el código pero sin implementación funcional. Las notificaciones de WhatsApp van por Twilio; el WhatsApp Cloud API SDK instalado no está activo.

#### R-12 — Sin tests funcionales

PHPUnit está configurado (`phpunit.xml`) pero no existen tests de Feature o Unit para la lógica de negocio core (creación de programas, cálculo de alertas, login). Solo existe el scaffolding de Laravel.

#### R-13 — Código comentado en `ProgramsController`

El bloque original de construcción de días por `tasks` (lógica antigua) está completamente comentado y reemplazado por lógica de `alerts`. El código comentado debería eliminarse.

#### R-14 — `filament/spatie-laravel-settings-plugin` instalado pero no usado

Dependencia en `composer.json` instalada sin uso detectado en el código fuente.

#### R-15 — Método muerto `addTaskToProgramFromProtocol`

`ProgramsController::addTaskToProgramFromProtocol()` existe pero no está conectado a ninguna ruta. Es código muerto.

---

## 9. Resumen Ejecutivo

| Categoría | Estado | Observaciones |
|---|---|---|
| Arquitectura | Sólida | MVC + Actions + multi-tenancy Filament bien implementado |
| API REST | Funcional con riesgos | 23 endpoints; falta IDOR protection y firma de webhooks |
| Base de datos | Completa | 30 tablas, dominio bien modelado; inconsistencias menores |
| Notificaciones | Funcional | Twilio operativo; WhatsApp Cloud API instalado pero sin uso |
| **Seguridad autenticación** | **CRÍTICO** | **Hash de contraseña comentado — bypass total de auth** |
| Autorización | Parcial | Multi-tenancy correcto en Filament; API sin Policies formales |
| Jobs / Scheduler | Funcional con bug | Bug en `delivered_at` con múltiples recipients |
| Testing | Ausente | Sin cobertura de lógica de negocio |
| Calidad de código | Buena base | Convenciones Laravel respetadas; dead code sin limpiar |
| Documentación técnica | Parcial | CLAUDE.md existe; sin OpenAPI/Swagger spec |

### Métricas del proyecto

| Métrica | Valor |
|---|---|
| Migraciones | 56 |
| Tablas efectivas | ~30 |
| Modelos Eloquent | 29 |
| Endpoints API | 23 |
| Recursos Filament SuperAdmin | 8 |
| Recursos Filament Vet | 11 |
| Jobs schedulados | 2 |
| Roles del sistema | 6 |
| Clases de notificación | 5 |
| Content SIDs Twilio | 9 |

---

> **Acción inmediata recomendada:** Restaurar la verificación de contraseña en `AuthController::login` línea 26 (descomentar `|| !Hash::check($request->password, $user->password)`). Mientras permanezca comentada, la API no tiene autenticación real y cualquier atacante con un username válido puede obtener un token con acceso completo.

---

*Documento generado el 2026-06-20. Basado en análisis estático del código fuente rama `develop`.*
