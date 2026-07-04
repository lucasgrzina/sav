# Reglas duras del dominio SAV (no negociables)

Estas reglas aplican a TODO el pipeline de desarrollo de SAV (Sistema de Alertas Veterinarias). Si un artefacto — ticket, spec, plan o código — viola alguna, es un error que debe marcarse o corregirse según el contexto del agente que las lea.

## 1. ProtocolTask — days_offset obligatorio

Toda tarea de protocolo tiene `days_offset` (int) + `time_of_day` (enum: `Before`/`After`). Un `ProtocolTask` sin estos dos campos está incompleto. Si un requerimiento agrega o modifica tareas de protocolo sin definir cuándo ocurren respecto al D0, es una ambigüedad crítica.

## 2. ProtocolAlert — roles explícitos

`ProtocolAlert.roles` es un JSON array. Valores válidos: `[vet, vet-assistant, client-owner, client-manager]`. "Notificar al usuario" sin especificar qué rol es una ambigüedad crítica — es una decisión de negocio que debe resolverse antes de continuar.

## 3. Año ganadero (AR) = julio–junio

`HealthPlan.year` referencia julio N → junio N+1, NO el año calendario. Si un requerimiento toca planes sanitarios usando lógica de año calendario, es un riesgo.

## 4. Multi-tenant — scope obligatorio

Cada `vet` es un tenant. Toda query, índice y scope en el código DEBE filtrar por el tenant del vet autenticado. Una consulta sin scope de tenant es una violación de seguridad crítica.

## 5. Multi-país desde el diseño

Argentina es el mercado inicial. El modelo ya soporta MX, PE, CO, CL, BR. Lógica hardcodeada de Argentina (RENSPA, CUIT, caravana) sin contemplar equivalentes de otros países es un riesgo de arquitectura.

## 6. GUID como identificador

URLs y payloads siempre usan `guid` (UUID string, 36 chars). Nunca el `id` interno. Todos los modelos usan trait `HasGuid` y `getRouteKeyName()` retorna `'guid'`. Si un endpoint, Resource o ruta usa `$id` numérico, es un error.

## 7. require_confirmation en tareas físicas

Si alguien debe confirmar físicamente que ejecutó una acción en campo (ej: "confirmar que se realizó la IA"), el modelo debe incluir `require_confirmation` (bool), `confirmed_at` (timestamp nullable), y `confirmed_by` (FK nullable). Si el requerimiento describe una tarea de confirmación sin este mecanismo, es una decisión implícita que debe resolverse.

## 8. API es fuente de verdad del contrato

El frontend NO inventa endpoints ni campos. El Resource backend define el shape que el frontend consume. Si el frontend necesita un endpoint nuevo, primero se define en el plan del backend.
