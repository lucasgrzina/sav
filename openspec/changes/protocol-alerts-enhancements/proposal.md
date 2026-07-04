## Why

El cliente (veterinario, usuario final del dominio SAV) explicó en dos audios de contexto (`docs/audios/proposito_SAV.txt`, `docs/audios/protocolos_sanitarios_SAV.txt`) tres necesidades que hoy no están cubiertas por el sistema de Protocolos/Programas/Alertas ya implementado: (1) recordatorios administrativos post-protocolo (facturación/cobro) que hoy no existen porque el módulo de Facturación es standalone y sin alertas propias; (2) las notificaciones salen sin identificar a la veterinaria/profesional que las envía, lo que le resta confianza y personalización al mensaje que recibe el cliente final; (3) el veterinario que atiende varios establecimientos en paralelo (4 a 10 campos con protocolos en distintos pasos simultáneos) no tiene una vista consolidada para orientarse rápido. Estas tres brechas fueron confirmadas contra el código actual y las decisiones de alcance ya fueron tomadas con el usuario (ver memoria `audio_analysis_questions_alertas_protocolos`).

## What Changes

- Nuevas `ProtocolTask`/`ProtocolAlert` con rol `vet-administrative` y `days_offset` positivo, para modelar recordatorios de facturación/cobro post-protocolo reutilizando el motor de alertas existente (`Alert`, `UpsertProgramAlertsAction`), sin tocar el módulo de Facturación.
- El rol `client-manager` (mapeado a "encargado de campo") recibe por defecto el mayor volumen de alertas de tareas de protocolo, salvo exclusión explícita del vet.
- Nueva capacidad de firma/branding personalizable por vet en las plantillas de notificación (WhatsApp/Twilio), aplicable tanto a alertas de `Program`/`Protocol` como a las de `HealthPlan` (calendario sanitario).
- Nuevo dashboard consolidado para el vet: vista de todos los `Program` activos agrupados por establecimiento/cliente, mostrando en qué paso/día está cada uno.
- **Fix asociado (no negociable, dependencia técnica)**: corregir el bug conocido en `SendAlertsNotifications` donde `delivered_at` se marca dentro del loop de recipients, causando que solo el primer destinatario reciba la notificación cuando una alerta tiene múltiples recipients. Se vuelve crítico al sumar `vet-administrative` como recipient adicional.

No se incluye en este change (decidido explícitamente fuera de alcance):
- Límite/digest automático de alertas (el control manual vía `ProtocolAlert.roles` + `important` ya alcanza).
- Atribución de autoría en protocolos "enlatados" (alcanza con `vet_id = null` vs `vet_id != null`).

## Capabilities

### New Capabilities
- `protocol-administrative-alerts`: alertas de protocolo dirigidas al rol `vet-administrative` para tareas post-fecha objetivo (facturación/cobro), reutilizando `ProtocolTask`/`ProtocolAlert`/`Alert`.
- `notification-branding`: personalización de firma/marca (nombre de veterinaria o profesional) por vet en las plantillas de notificación salientes (Twilio/WhatsApp), aplicable a alertas de Program/Protocol y de HealthPlan.
- `program-dashboard`: vista consolidada para el vet de todos los `Program` activos, agrupados por establecimiento/cliente, con el paso/día actual de cada uno.

### Modified Capabilities
(ninguna — no existe hoy spec de OpenSpec para `protocols`, `programs`, `alerts` ni `health-plans`; el capability `notifications` existente en `openspec/specs/notifications/spec.md` es un sistema de notificaciones in-app no relacionado —pivote `notification_recipients` con `read_at`— y no se modifica en este change)

## Impact

- **Backend**: `ProtocolTask`, `ProtocolAlert`, `Alert`, `UpsertProgramAlertsAction`, `SendAlertsNotifications` (fix de bug), clases de notificación Twilio (`ProgramTaskNotification`, `HealthPlanMonthNotification`, y potencialmente nuevas), modelo `Vet` (o tabla de configuración) para almacenar firma/branding.
- **Frontend**: nuevo módulo/página de dashboard consolidado multi-establecimiento; posibles ajustes en formularios de `ProtocolAlertsRelationManager` para el nuevo rol `vet-administrative`; configuración de firma/branding en el panel del vet.
- **Roles/Permisos**: `vet-administrative` pasa a ser recipient válido de `ProtocolAlert` (ya existe como rol del sistema, no se crea uno nuevo).
- **Dependencias**: motor de alertas existente (Twilio), scheduler (`everyMinute`), módulo de Técnicas y Protocolos, módulo de Programas Reproductivos, módulo de Planes Sanitarios.
