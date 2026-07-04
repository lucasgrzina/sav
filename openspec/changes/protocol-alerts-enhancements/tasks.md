## 1. Fix de bug — entrega de alertas multi-recipient (prerequisito)

- [ ] 1.1 Reproducir el bug con un test que cree un `Alert` con 2+ recipients y verifique que hoy solo el primero recibe la notificación
- [ ] 1.2 Mover la asignación de `delivered_at` fuera del loop de recipients en `SendAlertsNotifications::handle()`, para que se marque una sola vez después de intentar notificar a todos
- [ ] 1.3 Loguear (sin bloquear el flujo) cualquier fallo individual de envío por recipient, manteniendo la regla de "no reintentos"
- [ ] 1.4 Test de regresión: alerta con recipients de distintos roles (`client-manager` + `vet-administrative`) recibe ambas notificaciones

## 2. Alertas administrativas de protocolo (`vet-administrative`)

- [ ] 2.1 Verificar que `vet-administrative` ya existe como rol Spatie (no crear uno nuevo)
- [ ] 2.2 Habilitar `vet-administrative` como opción seleccionable en `ProtocolAlertsRelationManager` (panel Filament) al configurar `roles` de un `ProtocolAlert`
- [ ] 2.3 Confirmar en `UpsertProgramAlertsAction` que el filtro de recipients por `roles` ya soporta cualquier rol arbitrario (incluido `vet-administrative`) sin cambios de código — solo agregar test
- [ ] 2.4 Test: crear `Program` desde un protocolo con `ProtocolAlert` targeting `vet-administrative` y `days_offset` positivo → verificar que la `Alert` generada tiene como recipients solo usuarios con ese rol
- [ ] 2.5 Test: alerta administrativa con `send_at` calculado en el pasado no se crea (reutiliza regla existente)
- [ ] 2.6 Documentar en `docs/ANALISIS_FUNCIONAL.md` (sección 4 y 7) el nuevo rol soportado en `ProtocolAlert.roles`

## 3. Firma / branding en notificaciones

- [ ] 3.1 Migración: agregar columna `notification_signature` (string, nullable) a la tabla `vets`
- [ ] 3.2 Backfill: comando artisan que copie `vets.name` → `notification_signature` para vets existentes sin valor
- [ ] 3.3 Agregar campo `notification_signature` a `VetResource` (panel Filament) para que el vet lo edite
- [ ] 3.4 Modificar `ProgramTaskNotification`, `ProgramCreatedNotification`, `ProgramCanceledNotification` para incluir `alert->model->vet->notification_signature` (con fallback a `vet->name`) en el contenido/ContentVariables enviado a Twilio
- [ ] 3.5 Modificar `HealthPlanMonthNotification` de la misma forma
- [ ] 3.6 Test: notificación renderizada incluye la firma configurada
- [ ] 3.7 Test: vet sin firma configurada usa su `name` como fallback

## 4. Dashboard consolidado multi-establecimiento

- [ ] 4.1 Backend: nuevo endpoint `GET /api/v1/programs/dashboard` (o ruta equivalente bajo el namespace v1 existente) que devuelva `Program` no cancelados del vet autenticado, agrupados por `establishment_id`/`client_id`, reutilizando el cálculo de estado de `ProgramsController@show`
- [ ] 4.2 Restringir el endpoint a roles `vet`/`vet-assistant` (403 para roles client-side)
- [ ] 4.3 Test: dashboard devuelve solo programas del vet autenticado (scope de tenant)
- [ ] 4.4 Test: dashboard excluye programas cancelados y muestra el estado calculado correcto (Pending/In progress/Completed)
- [ ] 4.5 Test: rol `client-manager`/`client-owner`/`client-administrative` recibe 403 al intentar acceder
- [ ] 4.6 Frontend: nuevo módulo/página de dashboard consolidado (`front/src/modules/dashboard` o módulo dedicado) que consuma el endpoint y agrupe visualmente por establecimiento/cliente
- [ ] 4.7 Frontend: componente de tarjeta/fila por programa mostrando establecimiento, cliente, protocolo, estado y próxima alerta

## 5. Documentación y cierre

- [ ] 5.1 Actualizar `docs/diccionario-datos-er.md` con la nueva columna `vets.notification_signature`
- [ ] 5.2 Actualizar memoria de proyecto (`business_logic_flows` / `database_schema`) con las decisiones implementadas, y marcar como resuelta la memoria `audio_analysis_questions_alertas_protocolos`
- [ ] 5.3 Ejecutar `qa-backend` y `qa-frontend` sobre los módulos tocados antes de dar por cerrado el change
