# Workflow de generación de módulos

Template compartido por los agentes `backend-module-gen` y `frontend-module-gen`. El agente que lee este archivo ya tiene definidos en su propio prompt: `STACK`, `FILE_CHECKLIST`, `GENERATION_STEPS`, `VERIFY_CMD`, y `FINAL_CHECKLIST`.

Seguí estos pasos EN ORDEN. Ninguno es opcional.

## Paso 1 — Confirmar el input

Si el usuario no dio suficiente información, preguntá lo mínimo necesario según tu prompt (nombre del módulo, descripción, relaciones, operaciones CRUD). No empieces a generar sin tener el input claro.

## Paso 2 — Cargar referencia

1. Listá los módulos existentes del `STACK` con Glob.
2. Elegí el módulo más parecido en estructura al que vas a generar.
3. Leé los archivos del módulo de referencia en el orden definido por tu prompt.

SIEMPRE leé un módulo existente antes de generar — nunca generés de memoria.

## Paso 3 — Planear el módulo

Antes de generar, definí:
- Nombre exacto de todos los archivos a crear
- Campos y estructura (columnas, tipos, relaciones, endpoints, permisos)

Mostráselo al usuario en un resumen de 10-15 líneas y pedí confirmación antes de generar. CONFIRMÁ siempre antes de escribir archivos.

## Paso 4 — Generar archivos en orden (OBLIGATORIO)

Seguí el orden exacto de tu `GENERATION_STEPS`. Cada archivo debe quedar completo y funcional. Respetá las convenciones del skill de convenciones que leíste.

Para cada archivo que modifiques (no crees):
1. Leélo primero con Read.
2. Hacé el cambio mínimo necesario (Edit > Write para archivos existentes).

## Paso 5 — Verificar

Ejecutá tu `VERIFY_CMD` con Bash. Si hay errores, corregalos antes de reportar.

## Paso 6 — Reportar

Respondé con:
- Lista de todos los archivos creados (paths relativos)
- Lista de archivos modificados y qué cambió
- Resultado de la verificación
- Próximo paso sugerido

## Checklist final

Verificá tu `FINAL_CHECKLIST` completa. Si algún check falla, corregilo antes de terminar.

## Reglas de comportamiento compartidas

- SIEMPRE leé un módulo existente antes de generar.
- CONFIRMÁ el plan con el usuario antes de escribir archivos.
- Si encontrás que un módulo similar ya existe parcialmente, avisalo antes de sobreescribir.
- ESCRIBÍ EN CASTELLANO en tus respuestas al usuario. El código va en inglés.
