## Context

SAV ya tiene un motor de alertas funcionando end-to-end: `Protocol` → `ProtocolTask`/`ProtocolAlert` (plantillas) → `Program` (instancia) → `UpsertProgramAlertsAction` (genera `Alert` concretas) → `SendAlertsNotifications` (scheduler cada minuto, envía por Twilio/WhatsApp). El mismo patrón polimórfico (`alerts.model_class`/`model_id`) sirve para `HealthPlan` (calendario sanitario, `HealthPlanMonthNotification`).

El cliente pidió tres cosas que no rompen esta arquitectura, sino que la extienden: un nuevo rol recipient (`vet-administrative`) para tareas post-protocolo, personalización del remitente en el texto del mensaje, y una vista agregada sobre `Program` que hoy no existe. Además, hay un bug ya documentado (`docs/ANALISIS_FUNCIONAL.md` sección 7) que se vuelve más grave con esta feature: `delivered_at` se marca dentro del loop de recipients de `SendAlertsNotifications`, por lo que solo el primer recipient de una alerta multi-destinatario efectivamente la recibe. Hoy convive con esto porque la mayoría de las alertas tienen 1 recipient dominante; al sumar `vet-administrative` junto a `client-manager` en la misma tarea, el problema se vuelve visible y bloqueante.

## Goals / Non-Goals

**Goals:**
- Reutilizar el motor de `ProtocolTask`/`ProtocolAlert`/`Alert` existente para alertas administrativas — cero tablas nuevas para esto.
- Persistir una firma/branding por vet y aplicarla en el render de los mensajes existentes, sin cambiar la lista de `notification_class` disponibles.
- Exponer un endpoint de solo lectura para el dashboard consolidado, reutilizando el cálculo de estado ya existente en `ProgramsController@show`.
- Corregir el bug de `delivered_at` como parte de este change, porque es un prerequisito técnico para que la nueva alerta administrativa (segundo recipient) funcione de forma confiable.

**Non-Goals:**
- No se implementa límite/digest automático de alertas (decisión de producto ya tomada: alcanza con control manual por rol).
- No se agrega atribución de autoría a protocolos globales/enlatados (alcanza con `vet_id null/not-null`).
- No se modifica el módulo de Facturación (`invoices`) ni se crea integración Program↔Invoice.
- No se toca el capability `notifications` existente en OpenSpec (sistema in-app de `read_at`, no relacionado al motor Twilio).

## Decisions

### 1. Alertas administrativas: extender `ProtocolAlert.roles`, no crear tabla nueva
`ProtocolAlert.roles` ya es un JSON de roles arbitrario. Agregar `vet-administrative` a esa lista no requiere migración de esquema, solo que el formulario del panel (`ProtocolAlertsRelationManager`) permita seleccionarlo. **Alternativa descartada**: crear un modelo `AdministrativeTask` separado — se rechaza porque duplicaría el motor de scheduling/envío sin beneficio.

### 2. Firma/branding: campo `notification_signature` en `vets`
Se agrega una columna nullable `notification_signature` (string) a la tabla `vets`. El texto se inyecta en el momento de renderizar cada `Notification` (`ProgramTaskNotification`, `ProgramCreatedNotification`, `ProgramCanceledNotification`, `HealthPlanMonthNotification`) leyendo `alert->model->vet->notification_signature` (o el `name` del vet como fallback). **Alternativa descartada**: tabla `vet_settings` separada — se rechaza por sobre-ingeniería dado que es un único campo de texto; si en el futuro se agregan más ajustes de branding (logo, color), se puede migrar a una tabla dedicada.

### 3. Dashboard consolidado: nuevo endpoint de solo lectura, sin nuevo modelo
`GET /api/v1/programs/dashboard` (o similar) reutiliza las queries ya existentes de `Program` filtradas por `vet_id`, agrupando por `establishment_id`/`client_id` y devolviendo el mismo cálculo de estado usado en `ProgramsController@show` (Pending/In progress/Completed, calculado no almacenado). **Alternativa descartada**: vista materializada o tabla de resumen — innecesario al volumen esperado (decenas de programas activos por vet, no miles).

### 4. Fix de `delivered_at`: mover el marcado fuera del loop de recipients
En `SendAlertsNotifications::handle()`, `delivered_at` debe asignarse una sola vez por `Alert`, después de iterar y notificar a **todos** los recipients (no dentro del loop). Los fallos individuales de envío se loguean pero no impiden marcar la alerta como procesada una vez que se intentó con todos los recipients (mantiene la regla existente de "no reintentos").

## Risks / Trade-offs

- **[Riesgo] El fix de `delivered_at` cambia comportamiento de producción ya en uso** → Mitigación: cubrir con test de regresión que verifique explícitamente que N recipients reciben notificación antes de marcar `delivered_at`; desplegar junto con monitoreo del volumen de notificaciones enviadas los primeros días.
- **[Riesgo] Cambiar el fallback de firma a `vet.name` puede exponer un nombre no pensado para cara al cliente** → Mitigación: al lanzar, pre-poblar `notification_signature` con el nombre comercial conocido de cada vet existente vía seeder/comando de migración de datos, no depender solo del fallback en runtime.
- **[Trade-off] El dashboard no usa cache ni vista materializada** → aceptable mientras el volumen de programas activos por vet sea bajo (decenas); si crece, se puede revisar sin cambiar el contrato de API.

## Migration Plan

1. Migración: agregar columna `notification_signature` (string, nullable) a `vets`.
2. Backfill opcional: comando artisan que copie `vets.name` a `notification_signature` para vets existentes sin valor (evita depender solo del fallback en runtime).
3. Fix de `SendAlertsNotifications` (bug de `delivered_at`) — desplegar antes o junto con la nueva alerta `vet-administrative`, nunca después (si se despliega después, el bug afectaría inmediatamente a las nuevas alertas multi-recipient).
4. Feature flags no aplican (proyecto no usa feature flags); el rollout es directo vía deploy normal del backend.
5. Sin rollback destructivo necesario: la columna nueva es nullable y el endpoint del dashboard es aditivo (no reemplaza nada existente).

## Open Questions

Ninguna pendiente — todas las dudas de dominio fueron resueltas con el usuario antes de este design (ver memoria `audio_analysis_questions_alertas_protocolos`). Quedan como decisiones de implementación a validar por el arquitecto/dev-backend: nombre exacto del endpoint del dashboard y si el fix de `delivered_at` se entrega como change independiente o dentro de este mismo change (se recomienda incluirlo aquí por ser dependencia dura).
