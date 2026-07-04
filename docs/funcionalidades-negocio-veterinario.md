# Funcionalidades del Negocio Veterinario — SAV

> Relevamiento realizado sobre `sav-back-develop` (Laravel 11).  
> Fecha: 2026-06-20

---

## Resumen ejecutivo

SAV es un sistema **especializado en gestión de programas reproductivos veterinarios** (MOET, FIV) con un robusto motor de alertas automáticas multicanal. No es una clínica veterinaria de propósito general: carece de historia clínica, cirugías, farmacia, internación y laboratorio.

---

## Matriz de funcionalidades

| Funcionalidad | Estado | Madurez | Observación |
|---|---|---|---|
| Gestión de mascotas / animales | Parcial | 40 % | Solo número de registro (RP) de donantes; sin datos clínicos |
| Dueños / clientes | Implementado | 95 % | CRUD completo, importación masiva desde Excel |
| Historia clínica | Ausente | 0 % | No existe ningún modelo ni pantalla |
| Vacunación (historial) | Parcial | 30 % | Solo aparece como actividad dentro de Planes de Salud; sin historial por animal |
| Cirugías | Ausente | 0 % | No existe ningún módulo |
| Turnos / agenda | Implementado | 90 % | Modelo `Event`, CRUD en panel Vet y API; tipos: Tacto, Visita, Control sanitario, Cumpleaños, Otros |
| Internación | Ausente | 0 % | No existe ningún módulo |
| Farmacia / Stock | Ausente | 0 % | No existe ningún módulo |
| Facturación | Parcial | 50 % | Modelos `Invoice` / `InvoiceLine` con estructura básica; sin integración con programas ni reportes |
| Caja / Tesorería | Ausente | 0 % | Ningún modelo de pagos, flujo de caja ni conciliación |
| Laboratorio | Ausente | 0 % | No existe ningún módulo |
| Recordatorios / Alertas | Implementado | 100 % | Sistema polimórfico completo; SMS, WhatsApp y Email; envío programado; confirmación opcional |

---

## Detalle por funcionalidad

### 1. Gestión de mascotas / animales — PARCIAL

**Modelos**: `Animal`, `AnimalsGroup`

Los animales están modelados como **donantes en programas reproductivos**. La jerarquía es:

```
Client → Establishment → AnimalsGroup → Animal
```

**Atributos disponibles**: únicamente `rp_donor` (número de registro provincial).

**Lo que falta**: especie, raza, sexo, fecha de nacimiento, color, peso, chip/tatuaje, foto, propietario individual, historial médico propio.

---

### 2. Dueños / Clientes — IMPLEMENTADO

**Modelo**: `Client`

Atributos: nombre, CUIT/CUIL, dirección, ciudad, estado, país, teléfono x2, email.  
Relaciones: múltiples establecimientos, múltiples usuarios (portal), planes de salud.

**Admin Filament**: `ClientResource` con CRUD completo, importación masiva desde Excel, relation managers para establecimientos, usuarios y planes de salud.

**API**: `GET /api/v1/clients`, `GET /api/v1/clients/{id}/establishments`, `GET /api/v1/clients/{id}/managers`.

---

### 3. Historia clínica — AUSENTE

No existe ningún modelo, migración, recurso Filament ni endpoint API relacionado con consultas, diagnósticos, tratamientos o antecedentes médicos de pacientes.

---

### 4. Vacunación — PARCIAL

Las vacunaciones aparecen como **actividades dentro de los Planes de Salud** (`HealthActivity`), con asignación por mes. Se envía una alerta automática 7 días antes.

**Lo que falta**: registro individual por animal, lote/número de vacuna, laboratorio, dosis, veterinario que la aplicó, certificado, vencimiento de próxima dosis, historial acumulado.

---

### 5. Cirugías — AUSENTE

No existe ningún módulo de cirugías ni intervenciones quirúrgicas.

---

### 6. Turnos / Agenda — IMPLEMENTADO

**Modelo**: `Event`

Atributos: nombre, tipo, fecha, hora, veterinario, cliente.  
Tipos disponibles: Tacto, Visita, Control sanitario, Cumpleaños, Otros.  
Alertas: configurables 0–7 días antes, enviadas por cualquier canal.

**Admin Filament**: `AgendaResource` con vista de tabla y formulario.

**API**: `GET/POST/PUT /api/v1/agenda`, `GET /api/v1/agenda/{id}`.

---

### 7. Internación — AUSENTE

No existe ningún módulo de internación, estadías o seguimiento de pacientes hospitalizados.

---

### 8. Farmacia / Stock — AUSENTE

No existen modelos de medicamentos, inventario, compras, vencimientos ni dispensación.

---

### 9. Facturación — PARCIAL

**Modelos**: `Invoice`, `InvoiceLine`

Atributos de `Invoice`: código, fecha, fecha de vencimiento, estado, tipo, moneda (ARS por defecto), monto total, `vet_id`.  
Atributos de `InvoiceLine`: descripción, precio, impuesto, descuento, total.

**Admin Filament**: `InvoiceResource` con tabla de lectura.

**Lo que falta**: vinculación automática a programas/planes de salud, gestión de clientes en la factura, exportación a PDF/AFIP, reportes, notas de crédito/débito.

---

### 10. Caja / Tesorería — AUSENTE

Existe estructura de facturación, pero no hay modelo de pagos, flujo de caja, cuentas bancarias ni conciliación.

---

### 11. Laboratorio — AUSENTE

No existe ningún módulo de laboratorio, solicitudes de análisis ni resultados vinculados a pacientes.

---

### 12. Recordatorios / Alertas — IMPLEMENTADO COMPLETO

**Modelo**: `Alert` (polimórfico), `ProtocolAlert` (plantilla)

Funciona para tres tipos de entidades:
- `Program` → alertas de tareas reproductivas
- `HealthPlan` → alertas de actividades de salud por mes
- `Event` → recordatorios de agenda

**Canales de envío**: SMS (Twilio), WhatsApp (Cloud API), Email (SMTP).

**Características**:
- Timing relativo a una fecha objetivo (N días antes/después a una hora específica)
- Envío automático vía scheduler (`SendAlertsNotifications` job)
- Destinatarios selectivos por rol (Vet, Client, etc.)
- Confirmación opcional (`require_confirmation`)
- Webhook de respuesta entrante WhatsApp
- Lista negra `opt_out_numbers` para excluir teléfonos

---

## Funcionalidades propias del dominio SAV (fuera de la lista estándar)

Estas funcionalidades no son comunes a clínicas generales pero son el núcleo del sistema:

| Funcionalidad | Estado | Descripción |
|---|---|---|
| Programas reproductivos (MOET/FIV) | Implementado | Gestión completa de programas de transferencia embrionaria y fertilización in vitro |
| Técnicas y protocolos | Implementado | Catálogo jerárquico de técnicas; protocolos con tareas y alertas plantilla reutilizables |
| Planes de salud | Implementado | Planes anuales de actividades por establecimiento, con alertas automáticas mensuales |
| Cronograma PDF | Implementado | Generación de PDF con el cronograma de tareas de cada programa |
| Multi-tenant por veterinaria | Implementado | Cada veterinaria ve y gestiona solo sus propios datos |
| Importación masiva de clientes | Implementado | Excel → clientes + establecimientos via job asincrónico |

---

## Arquitectura técnica relevante

- **Backend**: Laravel 11, PHP 8.x
- **Admin panel**: Filament 3 (dos paneles: SuperAdmin y Vet)
- **API**: RESTful, prefijo `/api/v1/`, autenticación Sanctum
- **Roles**: Spatie Permission (SuperAdmin, Vet, Client)
- **Base de datos**: MySQL, 34 tablas + tablas Spatie
- **Notificaciones**: Twilio SMS, WhatsApp Cloud API, SMTP Email
- **Jobs**: `SendAlertsNotifications` (scheduler), `ImportClients` (async)
- **Modelos principales**: 28 modelos Eloquent
- **Timezone**: `America/Argentina/Buenos_Aires`

---

## Entidades del dominio (modelos)

| Modelo | Propósito | Soft Delete |
|---|---|---|
| `Client` | Dueños / clientes de la veterinaria | Sí |
| `Establishment` | Sucursales / campos del cliente | No |
| `Animal` | Animal individual (donante) | No |
| `AnimalsGroup` | Grupo de donantes | No |
| `Vet` | Veterinaria / organización | No |
| `User` | Usuarios del sistema | Sí |
| `Program` | Programa reproductivo (MOET/FIV) | No |
| `Technique` | Técnica reproductiva (jerárquica) | No |
| `Protocol` | Protocolo / plantilla de programa | Sí |
| `ProtocolTask` | Tarea plantilla del protocolo | No |
| `ProtocolAlert` | Alerta plantilla del protocolo | No |
| `Tasks` | Tarea concreta de un programa | No |
| `Alert` | Alerta generada y programada | No |
| `Event` | Evento de agenda | No |
| `HealthPlan` | Plan de salud anual | Sí |
| `HealthPlanTemplate` | Plantilla de plan de salud | Sí |
| `HealthPlanCategory` | Categoría de plan de salud | No |
| `HealthActivity` | Actividad (vacunación, desparasitación, etc.) | Sí |
| `Invoice` | Factura | No |
| `InvoiceLine` | Línea de factura | No |
| `Import` | Importación de clientes desde Excel | No |
| `ImportLog` | Errores de importación | No |
| `ProgramSetting` | Config. de PDF y branding por vet | No |
| `SupportMessage` | Ticket de soporte | No |
| `OptOutNumber` | Lista negra de SMS | No |
