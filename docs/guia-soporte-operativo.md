# Guía Operativa de Soporte — SAV Backend

> **Audiencia:** Desarrolladores nuevos que dan soporte al sistema.
> **Última actualización:** 2026-06-20
> **Stack:** Laravel 11 · PHP 8.x · MySQL · Filament 3 · Sanctum · Spatie Permissions

---

## Índice

1. [Arquitectura general](#1-arquitectura-general)
2. [Módulo: Autenticación](#2-módulo-autenticación)
3. [Módulo: Clientes y Establecimientos](#3-módulo-clientes-y-establecimientos)
4. [Módulo: Programas](#4-módulo-programas)
5. [Módulo: Alertas y Notificaciones](#5-módulo-alertas-y-notificaciones)
6. [Módulo: Agenda / Eventos](#6-módulo-agenda--eventos)
7. [Módulo: Planes Sanitarios](#7-módulo-planes-sanitarios)
8. [Módulo: Técnicas y Protocolos](#8-módulo-técnicas-y-protocolos)
9. [Módulo: Importación de Clientes](#9-módulo-importación-de-clientes)
10. [Módulo: WhatsApp Webhook](#10-módulo-whatsapp-webhook)
11. [Módulo: Facturación](#11-módulo-facturación)
12. [Panel Filament (Admin)](#12-panel-filament-admin)
13. [Workers y Tareas Programadas](#13-workers-y-tareas-programadas)
14. [Servicios Externos](#14-servicios-externos)
15. [Troubleshooting General](#15-troubleshooting-general)
16. [Variables de Entorno Críticas](#16-variables-de-entorno-críticas)

---

## 1. Arquitectura General

### Flujo de peticiones

```
Móvil / Web
    │
    ├── /api/v1/*       → routes/api.php
    │       └── app/Http/Controllers/Api/V1/
    │               └── app/Actions/  (lógica de dominio)
    │
    ├── /superadmin     → Filament SuperAdmin Panel
    │
    ├── /vet            → Filament Vet Panel
    │
    └── /*              → routes/web.php (landing, registro, webhooks)
```

### Modelo de tenencia

El sistema no tiene multi-tenancia de base de datos; la separación es lógica:

- Cada **Vet** (veterinario) es el tenant principal.
- **Clients**, **Protocols**, **Programs**, **Events**, **HealthPlans** están siempre acotados al `vet_id` del usuario autenticado.
- El panel Filament Vet usa `HasTenancy` de Filament para aislar datos automáticamente.

### Roles (Spatie Laravel Permission)

| Rol | Acceso |
|-----|--------|
| `SuperAdmin` | Panel `/superadmin`, acceso total |
| `Vet` | Panel `/vet`, acceso a sus propios datos |
| `Client` | Solo API, ve sus propios programas/planes |
| `Manager` | Sub-usuario de un Client, gestiona programas asignados |

---

## 2. Módulo: Autenticación

### Qué hace
Autentica usuarios vía Sanctum (tokens Bearer). El login devuelve un token que debe enviarse en el header `Authorization: Bearer {token}` en todas las peticiones protegidas.

### Tablas utilizadas
| Tabla | Uso |
|-------|-----|
| `users` | Credenciales y datos del usuario |
| `personal_access_tokens` | Tokens Sanctum activos |
| `password_reset_tokens` | Tokens de reset de contraseña |

### Archivos involucrados
```
app/Http/Controllers/Api/V1/AuthController.php
app/Notifications/UserRegisterNotification.php   ← Email con password temporal
routes/api.php                                    ← POST /login, POST /logout
```

### APIs expuestas
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/api/v1/login` | Login. Body: `{email, password}` |
| POST | `/api/v1/logout` | Revocar token actual |
| POST | `/api/v1/change-password` | Cambiar password. Body: `{current_password, password, password_confirmation}` |

### Errores frecuentes

| Error | Causa probable | Diagnóstico |
|-------|---------------|-------------|
| `401 Unauthenticated` | Token vencido o inválido | Verificar `personal_access_tokens` en BD; el cliente debe re-loginear |
| `422 Validation Error` en login | Email/password mal formateado | Revisar body del request |
| `These credentials do not match` | Credenciales incorrectas | Verificar `users.email` y `users.password` en BD |
| Usuario no puede loguear pero existe | `must_change_password = 1` | El usuario debe cambiar password primero (flujo de primer acceso) |

### Cómo diagnosticar
```bash
# Ver tokens activos de un usuario
php artisan tinker
>>> \App\Models\User::find($id)->tokens()->get(['name','last_used_at','expires_at'])

# Revocar todos los tokens de un usuario
>>> \App\Models\User::find($id)->tokens()->delete()
```

### Logs involucrados
- `storage/logs/laravel.log` — Excepciones de autenticación
- No hay logging específico de login/logout por defecto

### Impacto de cambios
- Modificar `AuthController` afecta **todos los clientes móviles**.
- Cambiar la tabla `users` requiere migración cuidadosa (SoftDelete activo desde 2024-01-16).

---

## 3. Módulo: Clientes y Establecimientos

### Qué hace
Gestiona la jerarquía: **Vet → Client → Establishment → AnimalsGroup → Animal**. Un veterinario puede tener múltiples clientes; cada cliente puede tener múltiples establecimientos con grupos de animales.

### Tablas utilizadas
| Tabla | Descripción |
|-------|-------------|
| `clients` | Empresas/personas cliente del veterinario |
| `client_vet` | Pivot: relación Vet↔Client |
| `establishments` | Localizaciones físicas de un cliente |
| `animals_groups` | Grupos de animales dentro de un establecimiento |
| `animals` | Animales individuales |
| `users` | Usuarios del cliente (managers) |

### Archivos involucrados
```
app/Http/Controllers/Api/V1/ClientsController.php
app/Models/Client.php          ← SoftDelete, scoped a vet
app/Models/Establishment.php
app/Models/AnimalsGroup.php
app/Models/Animal.php
app/Filament/Vet/Resources/ClientResource.php
```

### APIs expuestas
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/v1/clients` | Lista de clientes del vet autenticado |
| GET | `/api/v1/clients/{client}/establishments` | Establecimientos de un cliente |
| GET | `/api/v1/clients/{client}/managers` | Usuarios del cliente |
| GET | `/api/v1/clients/{establishment}/groups` | Grupos de animales con sus animales |

### Errores frecuentes

| Error | Causa | Diagnóstico |
|-------|-------|-------------|
| `404 Not Found` en client | Cliente eliminado (SoftDeleted) o de otro vet | Verificar `clients.deleted_at` y `client_vet.vet_id` |
| Cliente no aparece en lista | No está en `client_vet` | Verificar tabla pivot |
| Grupos vacíos | Animales sin grupo o grupo sin animales | Verificar `animals_groups.establishment_id` |

### Cómo diagnosticar
```bash
php artisan tinker
# Ver clientes de un vet
>>> \App\Models\Vet::find($vetId)->clients()->withTrashed()->get(['id','name','deleted_at'])

# Ver establecimientos de un cliente
>>> \App\Models\Client::find($clientId)->establishments()->get()
```

### Logs involucrados
- `storage/logs/laravel.log` — Errores de consulta

### Impacto de cambios
- `Client.php` tiene **SoftDelete**: nunca usar `delete()` directo en producción sin confirmación.
- Los scopes globales en `Client.php` filtran por `vet_id` automáticamente; desactivarlos expone datos de otros vets.

---

## 4. Módulo: Programas

### Qué hace
Es el módulo central del sistema. Un **Program** es un plan de trabajo que vincula un veterinario, un cliente, un establecimiento y un protocolo. Genera **Tasks** y **Alerts** basados en las tareas del protocolo seleccionado.

### Estado del programa (calculado)
| Estado | Lógica |
|--------|--------|
| `pending` | Sin alertas enviadas |
| `in_progress` | Al menos una alerta enviada, pero no todas |
| `completed` | Todas las alertas enviadas |
| `cancelled` | Campo `cancelled_at` no nulo |

### Tablas utilizadas
| Tabla | Descripción |
|-------|-------------|
| `programs` | Registros principales de programas |
| `tasks` | Tareas generadas del programa |
| `alerts` | Alertas generadas por el programa |
| `alert_user` | Destinatarios de cada alerta |
| `program_manager` | Usuarios managers asignados al programa |
| `protocol_tasks` | Plantilla de tareas del protocolo |
| `protocol_alerts` | Plantilla de alertas del protocolo |

### Archivos involucrados
```
app/Http/Controllers/Api/V1/ProgramsController.php
app/Actions/Programs/CreateProgramAction.php
app/Actions/Programs/UpdateProgramAction.php
app/Actions/Programs/CancelProgramAction.php
app/Actions/Programs/UpsertProgramAlertsAction.php
app/Models/Program.php
app/Models/Tasks.php
app/Models/Alert.php
app/Filament/Vet/Resources/ProgramResource.php
app/Notifications/ProgramCreatedNotification.php
app/Notifications/ProgramCanceledNotification.php
app/Notifications/ProgramCreated.php           ← Email con PDF
app/Notifications/ProgramUpdated.php           ← Email con PDF actualizado
```

### APIs expuestas
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/v1/programs` | Lista programas del usuario (vet o client) |
| POST | `/api/v1/programs` | Crear programa |
| GET | `/api/v1/programs/{program}` | Detalle con tareas y alertas agrupadas |
| PUT | `/api/v1/programs/{program}` | Actualizar programa |
| POST | `/api/v1/programs/{program}/cancel` | Cancelar programa |
| POST | `/api/v1/tasks/{alert}/complete` | Confirmar alerta completada |

### Errores frecuentes

| Error | Causa | Diagnóstico |
|-------|-------|-------------|
| Programa creado sin alertas | Protocolo sin `protocol_alerts` | Verificar `protocol_alerts` del protocolo |
| `403 Forbidden` al ver programa | Usuario no es el vet ni manager del programa | Verificar `program_manager` pivot |
| PDF no adjunto en email | Error en generación del PDF o disco `projects` no configurado | Ver logs; verificar `filesystems.php` disco `projects` |
| Alerta ya confirmada no actualiza | `confirmed_at` ya seteado | Campo es de una sola escritura |

### Cómo diagnosticar
```bash
php artisan tinker
# Ver estado de alertas de un programa
>>> \App\Models\Program::find($id)->load(['tasks','alerts.users'])->toArray()

# Ver managers de un programa
>>> \App\Models\Program::find($id)->managers()->get(['id','name','email'])

# Forzar re-generación de alertas (con cuidado)
>>> app(\App\Actions\Programs\UpsertProgramAlertsAction::class)->execute($program)
```

### Logs involucrados
- `storage/logs/laravel.log` — Errores de creación/actualización
- Revisar `failed_jobs` si el email de creación no llega

### Impacto de cambios
- `UpsertProgramAlertsAction` modifica `alerts` y `alert_user`: cambios aquí afectan qué notificaciones se envían y a quién.
- Modificar `Program.php` (estado calculado) afecta la vista del cliente en la app móvil.

---

## 5. Módulo: Alertas y Notificaciones

### Qué hace
Envía notificaciones a los usuarios destinatarios de un programa o evento vía **Twilio (SMS/WhatsApp)** y **Email**. El `SendAlertsNotifications` job es el corazón de este módulo.

### Tablas utilizadas
| Tabla | Descripción |
|-------|-------------|
| `alerts` | Alerta a enviar (model_id, model_class, sent_at, confirmed_at) |
| `alert_user` | Pivot: usuario destinatario de la alerta |
| `opt_out_numbers` | Números que solicitaron baja de WhatsApp |
| `tasks` | Tarea asociada a la alerta |

### Archivos involucrados
```
app/Jobs/SendAlertsNotifications.php
app/Channels/TwilioNotificationsChannel.php
app/Channels/WhatsAppNotificationsChannel.php
app/Notifications/ProgramTaskNotification.php
app/Notifications/EventNotification.php
app/Notifications/HealthPlanMonthNotification.php
app/Models/Alert.php
console/routes/console.php                          ← Scheduler
```

### Flujo de envío de alertas
```
Scheduler (cada minuto)
    └── SendAlertsNotifications::dispatch()
            ├── Busca alertas vencidas y no enviadas (sent_at IS NULL, scheduled <= now)
            ├── Por cada alerta → por cada destinatario en alert_user
            │       ├── Twilio channel → SMS o WhatsApp template
            │       └── Email channel (si corresponde)
            └── Marca sent_at = now()
```

### Canales de envío
| Canal | Config env | Cuándo se usa |
|-------|-----------|----------------|
| Twilio SMS | `TWILIO_*` | Número sin WhatsApp activo |
| WhatsApp Cloud API | `WHATSAPP_*` | Número en lista de opt-in |
| Email | `MAIL_*` | Notificaciones de creación/cancelación |

### Errores frecuentes

| Error | Causa | Diagnóstico |
|-------|-------|-------------|
| Alertas no se envían | Job no está corriendo | Verificar `queue:work` o que `QUEUE_CONNECTION=sync` |
| `TwilioException` | Credenciales incorrectas o número inválido | Ver `storage/logs/laravel.log`; verificar `TWILIO_*` en `.env` |
| Alerta enviada dos veces | Race condition en scheduler | Verificar que `sent_at` se graba antes del envío (transacción) |
| Número en opt-out no filtra | Bug en `opt_out_numbers` lookup | Verificar `WhatsAppNotificationsChannel` |
| `failed_jobs` table tiene registros | Job falló | `php artisan queue:failed` para ver detalle |

### Cómo diagnosticar
```bash
# Ver alertas pendientes de envío
php artisan tinker
>>> \App\Models\Alert::whereNull('sent_at')->where('scheduled_at', '<=', now())->get()

# Ver jobs fallidos
php artisan queue:failed

# Re-intentar job fallido
php artisan queue:retry {id}

# Despachar manualmente el job de alertas
php artisan job:dispatch SendAlertsNotifications

# Ver números en opt-out
>>> \App\Models\OptOutNumber::all()
```

### Logs involucrados
- `storage/logs/laravel.log` — Errores de Twilio/WhatsApp
- `failed_jobs` (tabla BD) — Jobs fallidos con payload completo
- Consola del proceso `queue:work` — Output en tiempo real

### Impacto de cambios
- Modificar `SendAlertsNotifications` afecta **todos los envíos de alertas** del sistema.
- Cambiar templates de Twilio requiere actualizar los IDs en `ProgramTaskNotification`.
- Agregar un canal nuevo requiere modificar `via()` en cada Notification class.

---

## 6. Módulo: Agenda / Eventos

### Qué hace
Gestiona eventos del calendario del veterinario. Cada evento puede tener una **Alert** opcional que notifica al cliente en la fecha/hora del evento.

### Tablas utilizadas
| Tabla | Descripción |
|-------|-------------|
| `events` | Eventos del calendario |
| `alerts` | Alertas opcionales asociadas al evento |
| `alert_user` | Destinatarios de la alerta del evento |

### Archivos involucrados
```
app/Http/Controllers/Api/V1/AgendaController.php
app/Models/Event.php           ← Trait HasAlertsTrait (cascade delete de alerts)
app/Filament/Vet/Resources/AgendaResource.php
app/Notifications/EventNotification.php
```

### APIs expuestas
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/v1/agenda` | Eventos del vet autenticado |
| GET | `/api/v1/agenda/{event}` | Detalle del evento con alerta |
| POST | `/api/v1/agenda` | Crear evento |
| PUT | `/api/v1/agenda/{event}` | Actualizar evento |

### Errores frecuentes

| Error | Causa | Diagnóstico |
|-------|-------|-------------|
| Alerta de evento no se envía | `alert.scheduled_at` en pasado al crear | Verificar que la fecha del evento es futura |
| Eliminar evento no elimina alerta | `HasAlertsTrait` no disparado | El trait hace cascade delete; verificar que está importado en `Event.php` |

### Cómo diagnosticar
```bash
php artisan tinker
>>> \App\Models\Event::find($id)->load('alerts.users')->toArray()
```

### Logs involucrados
- `storage/logs/laravel.log`

### Impacto de cambios
- `HasAlertsTrait` en `Event.php` maneja eliminación en cascada de alertas: si se refactoriza, las alertas huérfanas pueden acumularse.

---

## 7. Módulo: Planes Sanitarios

### Qué hace
Gestiona **HealthPlans**: planes mensuales de actividades sanitarias para animales de un establecimiento. Cada plan tiene actividades con fechas por mes del año.

### Tablas utilizadas
| Tabla | Descripción |
|-------|-------------|
| `health_plans` | Planes sanitarios (año, vet, client, establishment) |
| `health_plan_categories` | Categorías de planes |
| `health_activities` | Actividades dentro de un plan |
| `health_plan_activity` | Pivot: actividad↔plan con datos de mes |
| `health_plan_templates` | Plantillas reutilizables |
| `health_plan_template_activity` | Actividades de plantilla |

### Archivos involucrados
```
app/Http/Controllers/Api/V1/HealthPlanController.php
app/Actions/HealthPlans/CreateHealthPlanAction.php
app/Actions/HealthPlans/UpdateHealthPlanAction.php
app/Models/HealthPlan.php
app/Filament/Vet/Resources/HealthPlanResource.php
app/Notifications/HealthPlanMonthNotification.php
```

### APIs expuestas
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/v1/health_plans` | Lista planes (vet o client view) |
| GET | `/api/v1/health_plans/{healthPlan}` | Detalle con actividades por mes |

### Errores frecuentes

| Error | Causa | Diagnóstico |
|-------|-------|-------------|
| Plan no aparece | Filtrado por `year` o `vet_id` | Verificar campo `year` en `health_plans` |
| Actividades vacías | Pivot `health_plan_activity` sin registros | Verificar datos de pivot |

### Cómo diagnosticar
```bash
php artisan tinker
>>> \App\Models\HealthPlan::find($id)->load(['activities','category'])->toArray()
```

### Impacto de cambios
- `CreateHealthPlanAction` y `UpdateHealthPlanAction` manejan el pivot de actividades; cambios aquí afectan qué meses aparecen en la app.

---

## 8. Módulo: Técnicas y Protocolos

### Qué hace
Las **Techniques** son el catálogo de procedimientos veterinarios (con estructura padre-hijo). Los **Protocols** son plantillas de trabajo que contienen tareas y alertas con offsets de tiempo.

### Tablas utilizadas
| Tabla | Descripción |
|-------|-------------|
| `techniques` | Catálogo de técnicas (jerarquía padre-hijo via `parent_id`) |
| `protocols` | Plantillas de programas (SoftDelete activo) |
| `protocols_tasks` | Tareas de la plantilla con `days_offset` y `time_of_day` |
| `protocol_alerts` | Alertas de la plantilla con destinatarios por rol |

### Archivos involucrados
```
app/Http/Controllers/Api/V1/TechniquesController.php
app/Models/Technique.php
app/Models/Protocol.php        ← SoftDelete
app/Models/ProtocolTask.php
app/Models/ProtocolAlert.php
app/Filament/Vet/Resources/ProtocolResource.php
app/Imports/ProtocolTasksImport.php
```

### APIs expuestas
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/v1/techniques` | Lista de técnicas por tipo |
| GET | `/api/v1/techniques/protocols` | Todos los protocolos del usuario |
| GET | `/api/v1/techniques/{technique}/protocols` | Protocolos de una técnica |

### Errores frecuentes

| Error | Causa | Diagnóstico |
|-------|-------|-------------|
| Protocolo no aparece | SoftDeleted o de otro vet | Verificar `protocols.deleted_at` y `protocols.vet_id` |
| Programa sin tareas al crear | `protocol_tasks` vacía en el protocolo | Verificar `protocol_tasks` del protocolo seleccionado |

### Cómo diagnosticar
```bash
php artisan tinker
>>> \App\Models\Protocol::withTrashed()->find($id)->load(['tasks','alerts'])->toArray()
```

### Impacto de cambios
- Modificar la estructura de `ProtocolTask` (especialmente `days_offset`) afecta **cuándo se generan las alertas** de todos los programas futuros.

---

## 9. Módulo: Importación de Clientes

### Qué hace
Permite importar clientes masivamente desde un archivo Excel. El proceso es asíncrono: se guarda el archivo, se crea un registro `Import`, y un Job procesa la importación en background.

### Tablas utilizadas
| Tabla | Descripción |
|-------|-------------|
| `imports` | Registro de archivos de importación con estado |
| `import_logs` | Log por fila procesada (éxito/error) |
| `clients` | Clientes creados/actualizados |
| `establishments` | Establecimientos creados |

### Estados del Import
```
PENDING → ON_GOING → COMPLETED
                   → ERROR
```

### Archivos involucrados
```
app/Jobs/ImportClients.php
app/Imports/ClientsImport.php
app/Models/Import.php
app/Models/ImportLog.php
app/Filament/Vet/Resources/ImportResource.php
app/Exports/ClientsExampleExport.php
routes/web.php → GET /clients_export    ← Descarga template Excel
```

### Errores frecuentes

| Error | Causa | Diagnóstico |
|-------|-------|-------------|
| Import queda en `PENDING` | Queue no está corriendo | Verificar `queue:work` o `QUEUE_CONNECTION=sync` |
| Import en `ERROR` | Excepción en `ImportClients` job | Ver `import_logs` e `failed_jobs` |
| Filas con error silencioso | Fila inválida capturada en `onRow()` | Ver `import_logs` con `status = 'error'` |
| Template Excel no descarga | Ruta `/clients_export` con error | Verificar `ClientsExampleExport` y vista asociada |

### Cómo diagnosticar
```bash
php artisan tinker
# Ver estado de una importación
>>> \App\Models\Import::find($id)->load('logs')->toArray()

# Ver logs con errores
>>> \App\Models\ImportLog::where('import_id', $id)->where('status', 'error')->get()

# Despachar manualmente el job de importación
php artisan job:dispatch ImportClients
```

### Logs involucrados
- `storage/logs/laravel.log` — Excepciones del job
- `failed_jobs` — Si el job falla completamente
- Tabla `import_logs` — Log por fila

### Impacto de cambios
- `ClientsImport.php` usa `OnEachRow`: cada fila es procesada individualmente; errores en una fila no cancelan las demás.
- El formato del Excel de importación está definido en `ClientsExampleExport`; cambiarlo afecta el template que descargan los usuarios.

---

## 10. Módulo: WhatsApp Webhook

### Qué hace
Recibe mensajes entrantes de WhatsApp Cloud API. Procesa comandos de opt-in (`ALTA`) y opt-out (`BAJA`) para controlar si un número recibe notificaciones por WhatsApp.

### Tablas utilizadas
| Tabla | Descripción |
|-------|-------------|
| `opt_out_numbers` | Números con baja de WhatsApp |

### Archivos involucrados
```
app/Http/Controllers/Api/V1/WhatsAppController.php
routes/api.php  → POST /api/v1/whatsapp-webhook
routes/web.php  → POST /whatsapp-webhook   (ruta duplicada/espejo)
```

### Errores frecuentes

| Error | Causa | Diagnóstico |
|-------|-------|-------------|
| Webhook no recibe mensajes | Token de verificación incorrecto | Meta/WhatsApp requiere verificar el webhook con `hub.verify_token` |
| Opt-out no funciona | Mensaje no parsea como "BAJA" | Verificar uppercase y whitespace en el mensaje recibido |
| `500` en webhook | Excepción no capturada | Ver `storage/logs/laravel.log` |

### Cómo diagnosticar
```bash
# Verificar que la ruta responde
curl -X GET "https://tu-dominio.com/api/v1/whatsapp-webhook?hub.mode=subscribe&hub.verify_token=TU_TOKEN&hub.challenge=test"

# Ver números en opt-out
php artisan tinker
>>> \App\Models\OptOutNumber::all()
```

### Logs involucrados
- `storage/logs/laravel.log`
- Logs de Meta/WhatsApp Cloud API (panel de Meta for Developers)

---

## 11. Módulo: Facturación

### Qué hace
Registro interno de facturas del veterinario. No integra con sistemas de facturación externos.

### Tablas utilizadas
| Tabla | Descripción |
|-------|-------------|
| `invoices` | Cabecera de facturas |
| `invoice_lines` | Líneas de detalle |

### Archivos involucrados
```
app/Models/Invoice.php
app/Filament/Vet/Resources/InvoiceResource.php
```

### Errores frecuentes
- Errores son principalmente de validación de datos en el panel Filament.

---

## 12. Panel Filament (Admin)

### Paneles disponibles
| Panel | URL | Guard | Acceso |
|-------|-----|-------|--------|
| Vet | `/vet` | `vet` | Veterinarios |
| SuperAdmin | `/superadmin` | `superadmin` | Administradores del sistema |

### Recursos Vet Panel
| Recurso | Función |
|---------|---------|
| `ProgramResource` | CRUD de programas con gestión de tareas/alertas |
| `ClientResource` | CRUD de clientes con sub-recursos (establecimientos, grupos, planes, usuarios) |
| `HealthPlanResource` | CRUD de planes sanitarios |
| `ProtocolResource` | CRUD de protocolos/plantillas |
| `AgendaResource` | CRUD de eventos del calendario |
| `InvoiceResource` | CRUD de facturas |
| `ProgramSettingResource` | Configuración del PDF de programas |
| `ImportResource` | Gestión de importaciones de clientes |
| `UsersResource` | Gestión de usuarios del vet |
| `SupportMessageResource` | Bandeja de mensajes de soporte |
| `TutorialResource` | Contenido de ayuda/tutoriales |

### Archivos de configuración del panel
```
app/Providers/Filament/VetPanelProvider.php
app/Providers/Filament/SuperadminPanelProvider.php
config/filament.php
```

### Errores frecuentes

| Error | Causa | Diagnóstico |
|-------|-------|-------------|
| `403` en panel `/vet` | Usuario sin rol `Vet` o sin permisos Filament | Verificar roles Spatie del usuario |
| Panel no carga assets | Assets no compilados | `npm run build` |
| Tabla sin datos en panel | Scope de tenant no encuentra datos | Verificar que `vet_id` del usuario logueado coincide |

### Cómo diagnosticar
```bash
# Ver roles de un usuario
php artisan tinker
>>> \App\Models\User::find($id)->getRoleNames()

# Asignar rol a usuario
>>> \App\Models\User::find($id)->assignRole('Vet')
```

---

## 13. Workers y Tareas Programadas

### Jobs en cola
| Job | Trigger | Descripción |
|-----|---------|-------------|
| `SendAlertsNotifications` | Scheduler (periódico) | Envía alertas vencidas |
| `ImportClients` | Manual/Filament | Procesa importación de clientes |

### Scheduler
```bash
# Ejecutar manualmente
php artisan schedule:run

# Ver tareas programadas
php artisan schedule:list
```

### Configuración de cola
```env
# Síncrono (dev): procesa jobs inmediatamente
QUEUE_CONNECTION=sync

# Asíncrono (prod): requiere queue:work corriendo
QUEUE_CONNECTION=database
# o
QUEUE_CONNECTION=redis
```

### Comandos de gestión de cola
```bash
# Iniciar worker
php artisan queue:work

# Worker con reintentos y timeout
php artisan queue:work --tries=3 --timeout=90

# Ver jobs fallidos
php artisan queue:failed

# Reintentar job fallido
php artisan queue:retry {id}

# Limpiar jobs fallidos
php artisan queue:flush

# Despachar job manualmente
php artisan job:dispatch SendAlertsNotifications
php artisan job:dispatch ImportClients
```

### Logs involucrados
- `storage/logs/laravel.log` — Excepciones en jobs
- `failed_jobs` (tabla BD) — Jobs fallidos con payload y traza

---

## 14. Servicios Externos

### Twilio (SMS / WhatsApp via Twilio)
| Variable | Descripción |
|----------|-------------|
| `TWILIO_ACCOUNT_SID` | Account SID de Twilio |
| `TWILIO_AUTH_TOKEN` | Auth Token de Twilio |
| `TWILIO_FROM` | Número Twilio de origen |

**Diagnóstico:**
```bash
# Ver error en logs
grep -i "twilio" storage/logs/laravel.log

# Test manual (Tinker)
php artisan tinker
>>> $twilio = new \Twilio\Rest\Client(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'));
>>> $twilio->messages->create('+54911XXXXXXXX', ['from' => env('TWILIO_FROM'), 'body' => 'test']);
```

### WhatsApp Cloud API (Meta)
| Variable | Descripción |
|----------|-------------|
| `WHATSAPP_NUMBER_ID` | ID del número en Meta |
| `WHATSAPP_TOKEN` | Token de acceso de la app Meta |

**Diagnóstico:**
```bash
grep -i "whatsapp" storage/logs/laravel.log
```

### Email (SMTP)
| Variable | Descripción |
|----------|-------------|
| `MAIL_MAILER` | Driver (smtp, mailgun, postmark) |
| `MAIL_HOST` | Host SMTP |
| `MAIL_PORT` | Puerto SMTP |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | Credenciales |
| `MAIL_FROM_ADDRESS` | Dirección de origen |

**Diagnóstico:**
```bash
php artisan tinker
>>> \Illuminate\Support\Facades\Mail::raw('Test', fn($m) => $m->to('test@test.com')->subject('Test'));
```

### Almacenamiento de archivos
| Variable | Descripción |
|----------|-------------|
| `FILESYSTEM_DISK` | Default disk (local, s3) |
| `AWS_*` | Credenciales S3 (si se usa) |

PDFs de programas se guardan en el disco `projects` (configurar en `config/filesystems.php`).

---

## 15. Troubleshooting General

### Checklist inicial ante cualquier incidente

```bash
# 1. Ver últimos errores
tail -n 100 storage/logs/laravel.log

# 2. Estado de la aplicación
php artisan about

# 3. Verificar configuración de entorno
php artisan config:show database
php artisan config:show mail

# 4. Jobs fallidos
php artisan queue:failed

# 5. Caché de configuración desactualizada
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

---

### Problema: La API devuelve 500 Internal Server Error

**Pasos:**
1. `tail -n 50 storage/logs/laravel.log` — Ver la excepción completa con stack trace.
2. Verificar que `APP_ENV=local` y `APP_DEBUG=true` en desarrollo para ver el error en la respuesta.
3. En producción: `APP_DEBUG=false` siempre; revisar solo los logs.

---

### Problema: Notificaciones no llegan (WhatsApp / SMS)

**Pasos:**
1. Verificar que `SendAlertsNotifications` job está corriendo: `php artisan queue:failed`.
2. Verificar `alerts.sent_at` — si ya tiene fecha, la alerta ya se procesó.
3. Verificar que el número no está en `opt_out_numbers`.
4. Verificar credenciales Twilio/WhatsApp en `.env`.
5. Probar envío manual: `php artisan job:dispatch SendAlertsNotifications`.
6. Ver logs de Twilio/Meta en sus paneles respectivos.

---

### Problema: Importación de clientes queda en PENDING

**Pasos:**
1. Verificar `QUEUE_CONNECTION` en `.env` — si es `database` o `redis`, el worker debe estar corriendo.
2. `php artisan queue:work` en la terminal del servidor.
3. Ver `failed_jobs` si el job falló: `php artisan queue:failed`.
4. En desarrollo, cambiar a `QUEUE_CONNECTION=sync` para procesar inmediatamente.

---

### Problema: Usuario no puede acceder al panel Filament

**Pasos:**
1. Verificar rol del usuario:
   ```bash
   php artisan tinker
   >>> \App\Models\User::find($id)->getRoleNames()
   ```
2. Asignar rol si falta:
   ```bash
   >>> \App\Models\User::find($id)->assignRole('Vet')
   ```
3. Verificar guard del panel en `VetPanelProvider.php`.
4. Limpiar caché de sesión y pedir al usuario que re-loginee.

---

### Problema: Alerta enviada dos veces al mismo usuario

**Pasos:**
1. Verificar que no hay dos registros en `alert_user` para el mismo `alert_id` y `user_id`.
2. Verificar que `sent_at` se graba atómicamente (dentro de transacción DB).
3. Verificar que `queue:work` no tiene múltiples workers procesando el mismo job.

---

### Problema: PDF de programa no se genera / no se descarga

**Pasos:**
1. Verificar que el disco `projects` existe y tiene permisos de escritura.
2. Verificar configuración en `config/filesystems.php`.
3. Ver log de error al crear el programa.
4. Ruta de descarga: `GET /programs/{program}/get_file` en `routes/web.php`.

---

### Problema: Migración falla en deploy

**Pasos:**
```bash
# Ver estado de migraciones
php artisan migrate:status

# Ejecutar en modo dry-run (solo mostrar SQL)
php artisan migrate --pretend

# Si hay conflicto, ver qué migración falla
php artisan migrate 2>&1 | tail -30
```

---

## 16. Variables de Entorno Críticas

```env
# Aplicación
APP_KEY=              # CRÍTICO: nunca debe estar vacío en producción
APP_ENV=production
APP_DEBUG=false       # CRÍTICO: false en producción siempre
APP_TIMEZONE=America/Argentina/Buenos_Aires

# Base de datos
DB_CONNECTION=mysql
DB_HOST=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

# Cola de trabajos
QUEUE_CONNECTION=database   # En producción; sync solo en desarrollo

# Autenticación Sanctum
SANCTUM_STATEFUL_DOMAINS=   # Dominios del frontend

# Twilio
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_FROM=

# WhatsApp Cloud API
WHATSAPP_NUMBER_ID=
WHATSAPP_TOKEN=

# Email
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="SAV"

# Storage
FILESYSTEM_DISK=local   # o s3
AWS_ACCESS_KEY_ID=      # Solo si se usa S3
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
```

---

## Comandos Artisan de Referencia Rápida

```bash
# Limpiar cachés (después de cambios en config/routes)
php artisan optimize:clear

# Ver rutas disponibles
php artisan route:list --path=api/v1

# Ver estado de la aplicación
php artisan about

# Queue
php artisan queue:work --tries=3
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush

# Scheduler manual
php artisan schedule:run

# Job manual
php artisan job:dispatch SendAlertsNotifications
php artisan job:dispatch ImportClients

# Permisos (Spatie)
php artisan permission:show        # Ver roles y permisos
php artisan permission:cache-reset # Limpiar caché de permisos

# Base de datos
php artisan migrate:status
php artisan migrate --pretend
```

---

*Documento generado para el equipo de soporte de SAV — Sistema de Alertas Veterinarias.*
