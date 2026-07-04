# Diccionario de Datos y Modelo ER — SAV

> Generado a partir de migraciones, modelos Eloquent y código fuente.  
> Base: Laravel 11 · MySQL · Fecha: 2026-06-20

---

## Índice

1. [Entidades principales del negocio](#1-entidades-principales-del-negocio)
2. [Diccionario de datos](#2-diccionario-de-datos)
3. [Descripción funcional por tabla](#3-descripción-funcional-por-tabla)
4. [Relaciones entre entidades y cardinalidades](#4-relaciones-entre-entidades-y-cardinalidades)
5. [Reglas de integridad detectadas](#5-reglas-de-integridad-detectadas)
6. [Diagrama ER textual](#6-diagrama-er-textual)

---

## 1. Entidades principales del negocio

El dominio SAV se organiza alrededor de **6 entidades nucleares**:

| # | Entidad | Tabla | Rol en el dominio |
|---|---|---|---|
| 1 | **Vet** | `vets` | Organización veterinaria. Es el **tenant** del sistema multi-tenant. Todos los datos quedan aislados por vet. |
| 2 | **Client** | `clients` | Productor / dueño de establecimientos. Es el cliente de la veterinaria. |
| 3 | **Program** | `programs` | Programa reproductivo (MOET, FIV). Es la entidad operativa central. Agrega técnica, protocolo, cliente, establecimiento y genera tareas + alertas. |
| 4 | **Alert** | `alerts` | Alerta programada de envío automático (SMS, WhatsApp, Email). Motor de valor del sistema. |
| 5 | **HealthPlan** | `health_plans` | Plan de salud anual de un establecimiento. Genera alertas mensuales de actividades. |
| 6 | **Protocol** | `protocols` | Plantilla reutilizable de tareas y alertas que define la ejecución de una técnica. |

**Entidades de soporte clave**: `User`, `Establishment`, `Technique`, `Event`, `AnimalsGroup`, `Animal`.

---

## 2. Diccionario de datos

### 2.1 `vets`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Nombre de la veterinaria |
| `address` | varchar(255) | NO | — | Dirección |
| `phone` | varchar(255) | NO | — | Teléfono principal |
| `cuit` | bigint | NO | — | CUIT de la razón social |
| `registration_number` | int | NO | — | Número de matrícula veterinaria |
| `university` | varchar(255) | YES | NULL | Universidad de formación |
| `college` | varchar(255) | YES | NULL | Colegio veterinario |
| `vet_id` | varchar(255) | YES | NULL | DNI del veterinario responsable |
| `slug` | varchar(255) | NO | — | Slug único para URLs |
| `validated_at` | timestamp | YES | NULL | Fecha de validación de la cuenta |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.2 `users`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Nombre completo |
| `username` | varchar(255) | NO | — | Nombre de usuario (único) |
| `email` | varchar(255) | YES | NULL | Email (único cuando presente) |
| `email_verified_at` | timestamp | YES | NULL | Verificación de email |
| `phone` | varchar(255) | YES | NULL | Teléfono (se usa para SMS/WhatsApp) |
| `password` | varchar(255) | NO | — | Hash bcrypt |
| `vet_id` | bigint unsigned | YES | NULL | FK → `vets.id` |
| `client_id` | bigint unsigned | YES | NULL | FK → `clients.id` (SET NULL on delete) |
| `remember_token` | varchar(100) | YES | NULL | |
| `password_changed_at` | timestamp | YES | NULL | Última vez que cambió contraseña |
| `must_change_password` | tinyint(1) | NO | 1 | Fuerza cambio de contraseña en próximo login |
| `deleted_at` | timestamp | YES | NULL | Soft delete |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.3 `clients`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Razón social o nombre del productor |
| `cuit_cuil` | varchar(255) | NO | — | CUIT/CUIL (único) |
| `address` | varchar(255) | YES | NULL | Dirección |
| `city` | varchar(255) | YES | NULL | Ciudad |
| `postal_code` | varchar(255) | YES | NULL | Código postal |
| `state` | varchar(255) | YES | NULL | Provincia |
| `country` | varchar(255) | YES | NULL | País |
| `phone_1` | varchar(255) | YES | NULL | Teléfono principal |
| `phone_2` | varchar(255) | YES | NULL | Teléfono alternativo |
| `email` | varchar(255) | YES | NULL | Email (único cuando presente) |
| `deleted_at` | timestamp | YES | NULL | Soft delete |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.4 `establishments`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `client_id` | bigint unsigned | NO | — | FK → `clients.id` (CASCADE) |
| `name` | varchar(255) | NO | — | Nombre del establecimiento / campo |
| `city` | varchar(255) | NO | — | Ciudad |
| `postal_code` | varchar(255) | YES | NULL | Código postal |
| `latitude` | decimal(10,8) | YES | NULL | Latitud GPS |
| `length` | decimal(11,8) | YES | NULL | Longitud GPS (nombre de columna incorrecto) |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.5 `animals_groups`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Nombre del lote / grupo |
| `establishment_id` | bigint unsigned | NO | — | FK → `establishments.id` |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.6 `animals`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `rp_donor` | varchar(255) | NO | — | Número de Registro Provincial de la donante |
| `animals_group_id` | bigint unsigned | NO | — | FK → `animals_groups.id` |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.7 `techniques`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Nombre de la técnica (ej: MOET, FIV) |
| `target_date_name` | varchar(255) | YES | NULL | Etiqueta de la fecha objetivo en la UI |
| `type` | varchar(255) | NO | 'technique' | Tipo: 'technique' o 'vaccine' |
| `parent_id` | bigint unsigned | YES | NULL | FK → `techniques.id` (auto-referencial) |
| `protocols_name` | varchar(255) | YES | NULL | Etiqueta de protocolos en la UI |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.8 `protocols`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Nombre del protocolo |
| `color` | varchar(255) | YES | NULL | Color identificador en la UI (hex) |
| `vet_id` | bigint unsigned | YES | NULL | FK → `vets.id` (SET NULL). NULL = protocolo global |
| `technique_id` | bigint unsigned | YES | NULL | FK → `techniques.id` (CASCADE) |
| `deleted_at` | timestamp | YES | NULL | Soft delete |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.9 `protocol_tasks`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `protocol_id` | bigint unsigned | NO | — | FK → `protocols.id` (CASCADE) |
| `description` | varchar(255) | NO | — | Descripción de la tarea |
| `days_offset` | int | NO | — | Días de offset respecto a la fecha objetivo |
| `time_of_day` | enum('Before','After') | NO | — | Si el offset es antes o después |
| `time` | time | NO | — | Hora de la tarea |
| `important` | tinyint(1) | NO | 0 | Marca la tarea como destacada |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.10 `protocol_alerts`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `protocol_id` | bigint unsigned | NO | — | FK → `protocols.id` |
| `text` | text | NO | — | Texto del mensaje de la alerta |
| `days_offset` | int | NO | — | Días de offset respecto a la fecha objetivo |
| `time_of_day` | varchar(255) | NO | — | 'Before' o 'After' |
| `time` | time | NO | — | Hora de envío |
| `roles` | json | YES | NULL | Array de roles destinatarios (ej: ["Vet","Client"]) |
| `require_confirmation` | tinyint(1) | NO | 0 | Si la alerta requiere confirmación del destinatario |
| `confirmed_at` | timestamp | YES | NULL | Fecha de confirmación |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.11 `programs`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `vet_id` | bigint unsigned | YES | NULL | FK → `vets.id` (SET NULL) |
| `client_id` | bigint unsigned | NO | — | FK → `clients.id` (CASCADE) |
| `establishment_id` | bigint unsigned | NO | — | FK → `establishments.id` (CASCADE) |
| `group_id` | bigint unsigned | YES | NULL | FK → `animals_groups.id` (NO ACTION) |
| `technique_id` | bigint unsigned | NO | — | FK → `techniques.id` (CASCADE) |
| `protocol_id` | bigint unsigned | NO | — | FK → `protocols.id` (CASCADE) |
| `target_date` | date | NO | — | Fecha objetivo del programa |
| `state` | varchar(255) | NO | — | Estado (pending/in_progress/completed/cancelled) |
| `comments` | text | YES | NULL | Observaciones libres |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.12 `tasks`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `program_id` | bigint unsigned | NO | — | FK → `programs.id` (CASCADE) |
| `description` | varchar(255) | NO | — | Descripción de la tarea |
| `date` | date | NO | — | Fecha calculada de la tarea |
| `time` | time | NO | — | Hora de la tarea |
| `important` | tinyint(1) | NO | 0 | Tarea destacada |
| `completed_at` | timestamp | YES | NULL | Fecha en que se completó |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

> **Nota**: El modelo `Tasks` usa `project_id` como fillable pero la columna en tabla es `program_id`. Discrepancia en la capa del modelo.

---

### 2.13 `alerts`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `model_id` | bigint unsigned | NO | — | ID de la entidad dueña (polimórfico) |
| `model_class` | text | NO | — | FQCN de la entidad dueña (Program, HealthPlan, Event) |
| `notification_class` | text | NO | — | FQCN de la notificación a disparar |
| `text` | text | NO | — | Texto del mensaje |
| `require_confirmation` | tinyint(1) | NO | 0 | Si requiere confirmación del destinatario |
| `confirmed_at` | timestamp | YES | NULL | Fecha de confirmación |
| `send_at` | timestamp | YES | NULL | Fecha/hora programada de envío |
| `delivered_at` | timestamp | YES | NULL | Fecha/hora de envío efectivo |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

> La relación polimórfica se resuelve manualmente (no usa `morphTo` de Eloquent) via `model_id` + `model_class`.

---

### 2.14 `alert_user` *(pivote)*

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `alert_id` | bigint unsigned | NO | — | FK → `alerts.id` |
| `user_id` | bigint unsigned | NO | — | FK → `users.id` |

---

### 2.15 `events`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Título del evento |
| `event_type` | varchar(255) | NO | — | Tipo: Tacto, Visita, Control sanitario, Cumpleaños, Otros |
| `date` | date | NO | — | Fecha del evento |
| `time` | varchar(8) | NO | — | Hora (almacenada como string) |
| `vet_id` | bigint unsigned | NO | — | FK → `vets.id` |
| `client_id` | bigint unsigned | YES | NULL | FK → `clients.id` |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.16 `health_plan_categories`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Nombre de la categoría (ej: Bovinos, Porcinos) |
| `deleted_at` | timestamp | YES | NULL | Soft delete |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.17 `health_plan_templates`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Nombre de la plantilla |
| `health_plan_category_id` | bigint unsigned | NO | — | FK → `health_plan_categories.id` |
| `deleted_at` | timestamp | YES | NULL | Soft delete |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.18 `health_activities`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `name` | varchar(255) | NO | — | Nombre de la actividad (ej: Vacunación, Desparasitación) |
| `deleted_at` | timestamp | YES | NULL | Soft delete |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.19 `health_plans`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `client_id` | bigint unsigned | NO | — | FK → `clients.id` (CASCADE) |
| `establishment_id` | bigint unsigned | NO | — | FK → `establishments.id` (CASCADE) |
| `name` | varchar(255) | NO | — | Nombre del plan |
| `year` | int | NO | — | Año del plan |
| `health_plan_category_id` | bigint unsigned | NO | — | FK → `health_plan_categories.id` |
| `vet_id` | bigint unsigned | NO | — | FK → `vets.id` |
| `deleted_at` | timestamp | YES | NULL | Soft delete |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.20 `health_plan_template_activity` *(pivote)*

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `health_plan_template_id` | bigint unsigned | NO | — | FK → `health_plan_templates.id` (CASCADE) |
| `health_activity_id` | bigint unsigned | NO | — | FK → `health_activities.id` (CASCADE) |
| `months` | varchar(255) | NO | — | Meses donde se realiza la actividad (ej: "1,3,6,9") |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.21 `health_plan_activity` *(pivote)*

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `health_plan_id` | bigint unsigned | NO | — | FK → `health_plans.id` (CASCADE) |
| `health_activity_id` | bigint unsigned | NO | — | FK → `health_activities.id` (CASCADE) |
| `months` | varchar(255) | NO | — | Meses donde se realiza la actividad |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.22 `invoices`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `code` | varchar(255) | NO | — | Código / número de comprobante |
| `date` | date | NO | — | Fecha de emisión |
| `due_date` | date | NO | — | Fecha de vencimiento |
| `vet_id` | bigint unsigned | NO | — | FK → `vets.id` (CASCADE) |
| `status` | varchar(255) | NO | — | Estado (ej: draft, sent, paid) |
| `type` | varchar(255) | NO | 'invoice' | Tipo de comprobante |
| `currency_code` | varchar(255) | NO | 'ARS' | Moneda ISO 4217 |
| `amount` | float | NO | 0 | Monto total |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.23 `invoice_lines`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `invoice_id` | bigint unsigned | NO | — | FK → `invoices.id` (CASCADE) |
| `description` | varchar(255) | NO | — | Descripción del ítem |
| `price` | float | NO | 0 | Precio unitario |
| `tax` | float | NO | 0 | Impuesto (%) |
| `discount` | float | NO | 0 | Descuento (%) |
| `total` | float | NO | 0 | Total del renglón |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.24 `program_settings`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `logo` | varchar(255) | YES | NULL | Ruta al logo para PDF |
| `title` | varchar(255) | YES | NULL | Título del encabezado del PDF |
| `subtitle` | text | YES | NULL | Subtítulo del encabezado del PDF |
| `vet_id` | bigint unsigned | YES | NULL | FK → `vets.id` |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.25 `imports`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `vet_id` | bigint unsigned | YES | NULL | FK → `vets.id` |
| `file_path` | varchar(255) | NO | — | Ruta del archivo Excel subido |
| `status` | varchar(255) | NO | 'PENDING' | Estado: PENDING, ON_GOING, COMPLETED, ERROR |
| `completed_at` | timestamp | YES | NULL | Fecha de finalización del proceso |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.26 `import_logs`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `import_id` | bigint unsigned | NO | — | FK → `imports.id` |
| `line_number` | int | YES | NULL | Fila del Excel con error |
| `error` | text | YES | NULL | Descripción del error |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.27 `support_messages`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `user_id` | bigint unsigned | NO | — | FK → `users.id` |
| `vet_id` | bigint unsigned | NO | — | FK → `vets.id` |
| `subject` | varchar(255) | NO | — | Asunto del ticket |
| `status` | varchar(255) | NO | 'Pending' | Estado: Pending, In Progress, Resolved |
| `message` | text | NO | — | Cuerpo del mensaje |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.28 `opt_out_numbers`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `phone` | varchar(50) | NO | — | Número de teléfono en lista negra |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.29 `tutorials`

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `id` | bigint unsigned | NO | auto | PK |
| `title` | varchar(255) | NO | — | Título del tutorial |
| `url` | varchar(255) | YES | NULL | URL del recurso (video, documento) |
| `order` | int | NO | 0 | Orden de visualización |
| `created_at` | timestamp | YES | NULL | |
| `updated_at` | timestamp | YES | NULL | |

---

### 2.30 Tablas de Spatie Permission

#### `roles`
| Columna | Tipo | Descripción |
|---|---|---|
| `id` | bigint | PK |
| `name` | varchar | Nombre del rol (SuperAdmin, Vet, Client, etc.) |
| `guard_name` | varchar | Guard de autenticación (web, api) |

#### `permissions`
| Columna | Tipo | Descripción |
|---|---|---|
| `id` | bigint | PK |
| `name` | varchar | Nombre del permiso |
| `guard_name` | varchar | Guard |

#### `model_has_roles` *(pivote)*
Asigna roles a modelos (User). PK compuesta: (role_id, model_id, model_type).

#### `model_has_permissions` *(pivote)*
Asigna permisos directos a modelos. PK compuesta: (permission_id, model_id, model_type).

#### `role_has_permissions` *(pivote)*
Asigna permisos a roles. PK compuesta: (permission_id, role_id).

---

### 2.31 Tablas de infraestructura de Laravel

| Tabla | Propósito |
|---|---|
| `personal_access_tokens` | Tokens Sanctum para autenticación API |
| `password_reset_tokens` | Tokens de restablecimiento de contraseña |
| `failed_jobs` | Cola de jobs fallidos para reintentos |
| `contacts` | Tabla vacía (sin implementación) |

---

### 2.32 Tablas pivote — resumen

| Tabla | Relación |
|---|---|
| `client_vet` | Client ↔ Vet (N:M) |
| `program_manager` | Program ↔ User (N:M) |
| `alert_user` | Alert ↔ User (N:M) |
| `health_plan_activity` | HealthPlan ↔ HealthActivity (N:M con pivot `months`) |
| `health_plan_template_activity` | HealthPlanTemplate ↔ HealthActivity (N:M con pivot `months`) |
| `model_has_roles` | Modelo ↔ Role (Spatie) |
| `model_has_permissions` | Modelo ↔ Permission (Spatie) |
| `role_has_permissions` | Role ↔ Permission (Spatie) |

---

## 3. Descripción funcional por tabla

| Tabla | Descripción funcional |
|---|---|
| `vets` | Representa a la **organización veterinaria** que usa el sistema. Es el **tenant** del modelo multi-tenant. Cada vet tiene sus propios clientes, programas, protocolos y usuarios aislados. |
| `users` | **Usuarios del sistema** en cualquier rol. Un usuario puede pertenecer a una vet (usuario operativo) o a un client (portal del cliente). Recibe alertas, gestiona programas. |
| `clients` | **Productores / dueños** de establecimientos. Son los clientes de la veterinaria. Pueden tener múltiples establecimientos y acceder al portal con sus propios usuarios. |
| `establishments` | **Establecimientos / campos** pertenecientes a un cliente. Son las ubicaciones físicas donde se realizan los programas reproductivos. Tienen coordenadas GPS. |
| `animals_groups` | **Lotes o grupos de animales donantes** dentro de un establecimiento. Se crean automáticamente al iniciar un programa reproductivo. |
| `animals` | **Animales individuales** (donantes) dentro de un grupo. Se identifican solo por su Registro Provincial (RP). |
| `techniques` | **Catálogo de técnicas reproductivas** (MOET, FIV) con estructura jerárquica padre-hijo. Define el nombre de la fecha objetivo y de los protocolos en la UI. |
| `protocols` | **Plantillas de ejecución** de una técnica. Cada protocolo define las tareas y alertas que se generan al crear un programa. Pueden ser globales (sin vet) o propias de una vet. |
| `protocol_tasks` | **Tareas plantilla** de un protocolo. Se copian como `tasks` concretas al crear un programa, con fechas calculadas sobre `target_date`. |
| `protocol_alerts` | **Alertas plantilla** de un protocolo. Se copian como `alerts` al crear un programa, con fechas calculadas y roles destinatarios. |
| `programs` | **Programas reproductivos** (MOET/FIV). Entidad operativa central del sistema. Agrupa cliente, establecimiento, técnica, protocolo y genera tareas y alertas automáticas. |
| `tasks` | **Tareas concretas** generadas en un programa a partir de las `protocol_tasks`. Tienen fecha calculada y se pueden marcar como completadas. |
| `alerts` | **Alertas programadas** de envío automático. Son polimórficas: pueden pertenecer a Program, HealthPlan o Event. El job scheduler las envía cuando `send_at <= now()`. |
| `alert_user` | Tabla pivote que registra qué **usuarios son destinatarios** de cada alerta. |
| `events` | **Eventos de agenda** (turnos, visitas, controles). Tienen alertas configurables para recordatorio previo. |
| `health_plan_categories` | **Categorías** de planes de salud (ej: Bovinos, Porcinos). Catálogo global administrado por SuperAdmin. |
| `health_plan_templates` | **Plantillas globales** de planes de salud con actividades predefinidas por mes. Reutilizables por cualquier vet. |
| `health_activities` | **Catálogo de actividades** de salud (Vacunación, Desparasitación, Control clínico, etc.). Catálogo global. |
| `health_plans` | **Planes de salud anuales** para un establecimiento. Contiene actividades programadas por mes y genera alertas automáticas 7 días antes de cada mes. |
| `health_plan_template_activity` | Pivote que relaciona plantillas con actividades, incluyendo los **meses** en que se realiza. |
| `health_plan_activity` | Pivote que relaciona planes concretos con actividades, incluyendo los **meses** en que se realiza. |
| `invoices` | **Facturas** emitidas por la vet. Estructura básica de comprobante con código, fechas, moneda y monto. |
| `invoice_lines` | **Renglones** de una factura con descripción, precio, impuesto, descuento y total. |
| `program_settings` | **Configuración de branding** de la vet para los PDF de programas (logo, título, subtítulo). |
| `client_vet` | Pivote que registra la **relación operativa** entre clientes y veterinarias. |
| `program_manager` | Pivote que asigna **gestores (usuarios)** a un programa. Los gestores reciben las alertas. |
| `imports` | **Importaciones masivas** de clientes desde archivos Excel. Procesadas de forma asincrónica. |
| `import_logs` | **Log de errores** de cada importación, con número de fila y descripción del problema. |
| `support_messages` | **Tickets de soporte** enviados por usuarios al equipo de la vet. |
| `opt_out_numbers` | **Lista negra de teléfonos** que optaron por no recibir SMS/WhatsApp. |
| `tutorials` | **Recursos de ayuda** (videos, documentos) disponibles en el panel. |

---

## 4. Relaciones entre entidades y cardinalidades

### 4.1 Relaciones de Vet (Tenant)

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| Vet | 1 | N | User | Una vet tiene muchos usuarios |
| Vet | N | M | Client | Una vet atiende a muchos clientes; un cliente puede tener varias vets (via `client_vet`) |
| Vet | 1 | N | Protocol | Una vet tiene muchos protocolos propios |
| Vet | 1 | N | Program | Una vet gestiona muchos programas |
| Vet | 1 | N | HealthPlan | Una vet gestiona muchos planes de salud |
| Vet | 1 | N | Event | Una vet tiene muchos eventos de agenda |
| Vet | 1 | N | Invoice | Una vet emite muchas facturas |
| Vet | 1 | N | Import | Una vet realiza muchas importaciones |
| Vet | 1 | N | SupportMessage | Una vet recibe muchos tickets |
| Vet | 1 | 1 | ProgramSetting | Una vet tiene una configuración de PDF |

### 4.2 Relaciones de Client

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| Client | N | M | Vet | Un cliente puede pertenecer a múltiples vets |
| Client | 1 | N | Establishment | Un cliente tiene muchos establecimientos |
| Client | 1 | N | User | Un cliente tiene muchos usuarios del portal |
| Client | 1 | N | HealthPlan | Un cliente tiene muchos planes de salud |
| Client | 1 | N | Event | Un cliente tiene muchos eventos agendados |
| Client | 1 | N | Program | Un cliente participa en muchos programas |

### 4.3 Cadena jerárquica de ubicación

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| Client | 1 | N | Establishment | Un cliente posee múltiples establecimientos |
| Establishment | 1 | N | AnimalsGroup | Un establecimiento tiene múltiples grupos de animales |
| AnimalsGroup | 1 | N | Animal | Un grupo contiene múltiples animales |

### 4.4 Relaciones de Program

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| Program | N | 1 | Client | Muchos programas a un cliente |
| Program | N | 1 | Establishment | Muchos programas a un establecimiento |
| Program | N | 1 | Technique | Muchos programas a una técnica |
| Program | N | 1 | Protocol | Muchos programas a un protocolo |
| Program | N | 1 | Vet | Muchos programas a una vet |
| Program | N | 1 | AnimalsGroup | Muchos programas a un grupo de donantes |
| Program | 1 | N | Tasks | Un programa genera muchas tareas concretas |
| Program | N | M | User | Un programa tiene muchos gestores (via `program_manager`) |
| Program | 1 | N | Alert | Un programa genera muchas alertas (polimórfico) |

### 4.5 Relaciones de Protocol (plantilla)

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| Protocol | N | 1 | Technique | Muchos protocolos por técnica |
| Protocol | N | 1 | Vet | Muchos protocolos propios de una vet (o global si vet_id=NULL) |
| Protocol | 1 | N | ProtocolTask | Un protocolo define muchas tareas plantilla |
| Protocol | 1 | N | ProtocolAlert | Un protocolo define muchas alertas plantilla |

### 4.6 Relaciones de Technique

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| Technique | N | 1 | Technique | Auto-referencia padre-hijo (ej: MOET → MOET 2024) |
| Technique | 1 | N | Protocol | Una técnica tiene muchos protocolos |

### 4.7 Relaciones de Alert

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| Alert | N | 1 | Program / HealthPlan / Event | Muchas alertas a una entidad dueña (polimórfico manual) |
| Alert | N | M | User | Una alerta tiene muchos destinatarios (via `alert_user`) |

### 4.8 Relaciones de HealthPlan

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| HealthPlan | N | 1 | Client | Muchos planes a un cliente |
| HealthPlan | N | 1 | Establishment | Muchos planes a un establecimiento |
| HealthPlan | N | 1 | Vet | Muchos planes a una vet |
| HealthPlan | N | 1 | HealthPlanCategory | Muchos planes a una categoría |
| HealthPlan | N | M | HealthActivity | Un plan tiene muchas actividades con pivot `months` |
| HealthPlan | 1 | N | Alert | Un plan genera muchas alertas (polimórfico) |

### 4.9 Relaciones de HealthPlanTemplate

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| HealthPlanTemplate | N | 1 | HealthPlanCategory | Muchas plantillas a una categoría |
| HealthPlanTemplate | N | M | HealthActivity | Una plantilla tiene muchas actividades con pivot `months` |

### 4.10 Relaciones de Invoice

| Entidad A | Cardinalidad | Entidad B | Descripción |
|---|---|---|---|
| Invoice | N | 1 | Vet | Muchas facturas a una vet |
| Invoice | 1 | N | InvoiceLine | Una factura tiene muchos renglones |

---

## 5. Reglas de integridad detectadas

### 5.1 Restricciones referencialesON DELETE

| Tabla (FK) | FK → Tabla referenciada | ON DELETE |
|---|---|---|
| `users.vet_id` | `vets.id` | Sin declarar (RESTRICT implícito) |
| `users.client_id` | `clients.id` | SET NULL |
| `clients` | `client_vet` | CASCADE |
| `vets` | `client_vet` | CASCADE |
| `establishments.client_id` | `clients.id` | CASCADE |
| `animals_groups.establishment_id` | `establishments.id` | Sin declarar (RESTRICT) |
| `animals.animals_group_id` | `animals_groups.id` | Sin declarar (RESTRICT) |
| `protocols.vet_id` | `vets.id` | SET NULL |
| `protocols.technique_id` | `techniques.id` | CASCADE |
| `protocol_tasks.protocol_id` | `protocols.id` | CASCADE |
| `programs.vet_id` | `vets.id` | SET NULL |
| `programs.client_id` | `clients.id` | CASCADE |
| `programs.establishment_id` | `establishments.id` | CASCADE |
| `programs.group_id` | `animals_groups.id` | NO ACTION |
| `programs.technique_id` | `techniques.id` | CASCADE |
| `programs.protocol_id` | `protocols.id` | CASCADE |
| `tasks.program_id` | `programs.id` | CASCADE |
| `health_plans.client_id` | `clients.id` | CASCADE |
| `health_plans.establishment_id` | `establishments.id` | CASCADE |
| `health_plan_template_activity.health_plan_template_id` | `health_plan_templates.id` | CASCADE |
| `health_plan_template_activity.health_activity_id` | `health_activities.id` | CASCADE |
| `health_plan_activity.health_plan_id` | `health_plans.id` | CASCADE |
| `health_plan_activity.health_activity_id` | `health_activities.id` | CASCADE |
| `invoices.vet_id` | `vets.id` | CASCADE |
| `invoice_lines.invoice_id` | `invoices.id` | CASCADE |

### 5.2 Restricciones de unicidad

| Tabla | Columnas únicas |
|---|---|
| `users` | `username` (única) |
| `clients` | `cuit_cuil` (única); `email` (única cuando presente) |
| `techniques` | *(ninguna declarada)* |
| `personal_access_tokens` | `token` (64 chars, única) |
| `password_reset_tokens` | `email` (PK) |
| `roles` | `(name, guard_name)` |
| `permissions` | `(name, guard_name)` |
| `program_manager` | `(program_id, user_id)` (índice único) |

### 5.3 Restricciones de negocio (soft constraints)

| Regla | Implementación |
|---|---|
| Los clientes solo son visibles para la vet autenticada | Global scope `vet` en modelo `Client` |
| Los protocolos solo son visibles para la vet autenticada (o globales sin vet) | Global scope `vet` en modelo `Protocol` |
| Los usuarios deben cambiar contraseña en el primer login | Columna `must_change_password` + lógica de middleware |
| Un número de teléfono en opt-out no recibe alertas | Se verifica antes de envío en `SendAlertsNotifications` |
| Las alertas solo se envían una vez | Se filtra `delivered_at IS NULL` en el job |
| El estado de un programa se calcula desde sus alertas | `state` es atributo computado en el modelo `Program` |
| Las alertas se generan 7 días antes de cada mes activo del plan de salud | Regla de negocio en `CreateHealthPlanAction` |

### 5.4 Anomalías y deudas técnicas detectadas

| Tipo | Descripción |
|---|---|
| Inconsistencia columna-modelo | `Tasks.$fillable` usa `project_id` pero la tabla tiene `program_id` |
| Columna mal nombrada | `establishments.length` debería ser `longitude` |
| Columna sin FK declarada | `establishments` no tiene columna `vet_id` pero el modelo la referencia |
| Relación polimórfica manual | `alerts.model_id + model_class` no usa `morphTo` de Eloquent; no hay FK real |
| Tabla vacía | `contacts` existe en BD pero el modelo no tiene implementación |
| FK no declarada | `protocol_alerts.protocol_id` no tiene FK declarada en migración |
| FK no declarada | `alert_user.alert_id` y `alert_user.user_id` sin FK explícitas en migración |

---

## 6. Diagrama ER textual

```
╔══════════════════════════════════════════════════════════════════════════╗
║                    DIAGRAMA ER — SAV BACKEND                           ║
╚══════════════════════════════════════════════════════════════════════════╝

┌─────────────────────────────────────────────────────────────────────────┐
│ TENANT (ORGANIZACIÓN)                                                   │
└─────────────────────────────────────────────────────────────────────────┘

        ┌──────────┐
        │   vets   │
        │──────────│
        │ id  PK   │
        │ name     │
        │ cuit     │
        │ slug     │
        └────┬─────┘
             │ 1
             │
    ┌────────┼─────────────────────────────────────────────┐
    │ N      │ N                     │ N       │ N          │ N
    ▼        ▼                       ▼         ▼            ▼
┌────────┐ ┌──────────┐       ┌──────────┐ ┌────────┐  ┌─────────┐
│ users  │ │protocols │       │ programs │ │ events │  │invoices │
│────────│ │──────────│       │──────────│ │────────│  │─────────│
│ id  PK │ │ id  PK   │       │ id  PK   │ │ id  PK │  │ id  PK  │
│ name   │ │ name     │       │target_   │ │ name   │  │ code    │
│username│ │ color    │       │  date    │ │ date   │  │ date    │
│ phone  │ │vet_id FK │       │ state    │ │ time   │  │ amount  │
│ email  │ │tech_id FK│       │ comments │ │vet_id  │  │vet_id FK│
│vet_id  │ └────┬─────┘       └────┬─────┘ │cli_id  │  └────┬────┘
│cli_id  │      │ 1                │            └────────┘       │ 1
└────────┘      │                  │                              │
                │                  └──────────────────────────────┼──→N
              ┌─┴──────────────┐                            ┌─────┴──────┐
              │  1             │ 1                          │invoice_    │
              ▼                ▼                            │  lines     │
        ┌──────────┐    ┌──────────┐                       └────────────┘
        │protocol_ │    │protocol_ │
        │  tasks   │    │  alerts  │
        │──────────│    │──────────│
        │ id  PK   │    │ id  PK   │
        │ description│  │ text     │
        │days_offset│   │days_     │
        │time_of_  │    │  offset  │
        │  day     │    │ roles    │
        └──────────┘    └──────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ CLIENTES Y ESTABLECIMIENTOS                                             │
└─────────────────────────────────────────────────────────────────────────┘

   ┌──────────┐      N:M via       ┌──────────┐
   │ clients  │◄───client_vet──────│   vets   │
   │──────────│                    └──────────┘
   │ id  PK   │ 1
   │ name     ├──────────────────────────────┐
   │ cuit_    │                              │ N
   │  cuil    │ 1                            ▼
   │ email    ├──────────┐          ┌────────────────┐
   └──────────┘          │ N        │ health_plans   │
                         ▼          │────────────────│
                  ┌──────────────┐  │ id  PK         │
                  │establishments│  │ name           │
                  │──────────────│  │ year           │
                  │ id  PK       │  │ client_id FK   │
                  │ name         │  │ estab_id  FK   │
                  │ city         │  │ vet_id    FK   │
                  │ latitude     │  └───────┬────────┘
                  │ length (lng) │          │ N:M via
                  │ client_id FK │    health_plan_activity
                  └──────┬───────┘          │
                         │ 1                ▼
                         │ N         ┌──────────────┐
                         ▼           │health_       │
                  ┌──────────────┐   │  activities  │
                  │animals_groups│   │──────────────│
                  │──────────────│   │ id  PK       │
                  │ id  PK       │   │ name         │
                  │ name         │   └──────────────┘
                  │ estab_id  FK │
                  └──────┬───────┘
                         │ 1
                         │ N
                         ▼
                  ┌──────────┐
                  │ animals  │
                  │──────────│
                  │ id  PK   │
                  │ rp_donor │
                  │ group_id │
                  └──────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ PROGRAMAS REPRODUCTIVOS (ENTIDAD CENTRAL)                              │
└─────────────────────────────────────────────────────────────────────────┘

   ┌──────────┐  ┌──────────┐  ┌──────────────┐  ┌────────────────┐
   │ clients  │  │   vets   │  │establishments│  │animals_groups  │
   └────┬─────┘  └────┬─────┘  └──────┬───────┘  └───────┬────────┘
        │              │               │                   │
        │ N:1          │ N:1           │ N:1               │ N:1
        └──────────────┴───────────────┴─────────┬─────────┘
                                                  │
                                           ┌──────┴──────┐
                                           │  programs   │ ◄── ENTIDAD CENTRAL
                                           │─────────────│
                                           │ id  PK      │
                                           │ target_date │
                                           │ state       │
                                           │ comments    │
                                           │ vet_id   FK │
                                           │ client_id FK│
                                           │ estab_id FK │
                                           │ group_id FK │
                                           │ tech_id  FK │◄── techniques
                                           │ proto_id FK │◄── protocols
                                           └──────┬──────┘
                                                  │ 1
                          ┌───────────────────────┼───────────────────────┐
                          │ N                     │ N                     │ N:M
                          ▼                       ▼                       ▼
                   ┌──────────┐           ┌──────────────┐       ┌──────────────┐
                   │  tasks   │           │    alerts    │       │program_mgr   │
                   │──────────│           │──────────────│       │(program_id,  │
                   │ id  PK   │           │ id  PK       │       │  user_id)    │
                   │description│          │ model_id     │       └──────┬───────┘
                   │ date     │           │ model_class  │              │ N:M
                   │ time     │           │ notif_class  │              ▼
                   │ important│           │ text         │           ┌──────┐
                   │completed_│           │ send_at      │           │users │
                   │  at      │           │ delivered_at │           └──────┘
                   └──────────┘           │ confirmed_at │
                                          └──────┬───────┘
                                                 │ N:M via alert_user
                                                 ▼
                                              ┌──────┐
                                              │users │
                                              └──────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ TÉCNICAS Y PROTOCOLOS (CONFIGURACIÓN)                                  │
└─────────────────────────────────────────────────────────────────────────┘

   ┌──────────────┐ 1     N ┌──────────┐ 1     N ┌──────────────┐
   │  techniques  ├─────────┤ protocols├─────────┤protocol_tasks│
   │──────────────│         │──────────│         └──────────────┘
   │ id  PK       │         │ id  PK   │
   │ name         │         │ name     │ 1     N ┌───────────────┐
   │ type         │         │ color    ├─────────┤protocol_alerts│
   │ parent_id FK │◄─────┐  │ vet_id FK│         └───────────────┘
   │ target_date_ │      │  │ tech_id  │
   │  name        │      │  │  FK      │
   └──────────────┘      │  └──────────┘
          1:N self        │
          (parent-child) ─┘


┌─────────────────────────────────────────────────────────────────────────┐
│ PLANES DE SALUD                                                         │
└─────────────────────────────────────────────────────────────────────────┘

   ┌──────────────────┐       ┌──────────────────────┐
   │health_plan_      │ 1   N │health_plan_templates │
   │  categories      ├───────┤──────────────────────│
   │──────────────────│       │ id  PK               │
   │ id  PK           │       │ name                 │
   │ name             │       │ cat_id FK            │
   └────────┬─────────┘       └──────────┬───────────┘
            │ 1                          │ N:M via
            │                    health_plan_template_activity
            │ N                          │
            ▼                            ▼
   ┌──────────────────┐       ┌──────────────┐
   │  health_plans    │       │health_       │
   │──────────────────│       │  activities  │
   │ id  PK           │       │──────────────│
   │ name             │       │ id  PK       │
   │ year             │       │ name         │
   │ client_id FK     │       └──────────────┘
   │ estab_id  FK     │              ▲
   │ cat_id    FK     │              │ N:M via
   │ vet_id    FK     │    health_plan_activity
   └────────┬─────────┘              │
            │ N:M ───────────────────┘
            │ 1
            │ N
            ▼
         ┌──────────────┐
         │    alerts    │ (polimórfico: model_class = HealthPlan)
         └──────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ AUTORIZACIÓN (SPATIE PERMISSION)                                        │
└─────────────────────────────────────────────────────────────────────────┘

   ┌──────────┐   N:M via        ┌──────────┐  N:M via   ┌─────────────┐
   │  users   │──model_has_roles─┤  roles   │─role_has───┤ permissions │
   └──────────┘                  └──────────┘ permissions└─────────────┘
        │                                                       ▲
        └──────────────────model_has_permissions────────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ OPERACIONES Y SOPORTE                                                   │
└─────────────────────────────────────────────────────────────────────────┘

   ┌──────────┐ 1   N ┌────────────┐ 1   N ┌────────────┐
   │   vets   ├───────┤  imports   ├───────┤import_logs │
   └──────────┘       └────────────┘       └────────────┘

   ┌──────────┐ 1   N ┌──────────────────┐
   │  users   ├───────┤ support_messages │
   └──────────┘       └──────────────────┘

   ┌─────────────────┐       ┌────────────────┐
   │  opt_out_numbers│       │program_settings│◄── 1:1 con vets
   │  (lista negra)  │       └────────────────┘
   └─────────────────┘

   ┌──────────────────────────────────────────────────┐
   │ personal_access_tokens  (Sanctum — API auth)     │
   │ password_reset_tokens                            │
   │ failed_jobs             (queue failures)         │
   │ tutorials               (recursos de ayuda)      │
   └──────────────────────────────────────────────────┘
```

---

### Leyenda del diagrama

```
──────  Línea de relación
1        Extremo "uno"
N        Extremo "muchos"
N:M      Relación muchos-a-muchos (indicada con tabla pivote)
PK       Primary Key
FK       Foreign Key
◄──      La flecha apunta hacia la tabla referenciada
via X    Relación resuelta a través de tabla pivote X
```

---

### Resumen de totales

| Concepto | Cantidad |
|---|---|
| Tablas de dominio | 29 |
| Tablas pivote | 8 |
| Tablas de infraestructura Laravel | 4 |
| Tablas Spatie Permission | 5 |
| **Total de tablas** | **46** |
| Modelos Eloquent | 28 |
| Relaciones 1:N declaradas | 34 |
| Relaciones N:M declaradas | 8 |
| Relaciones polimórficas (manual) | 1 |
| Soft deletes en tablas | 7 |
