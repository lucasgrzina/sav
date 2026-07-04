# Workflow de generación de artefactos

Template compartido por los agentes `ticket-builder`, `funcional`, y `arquitecto`. El agente que lee este archivo ya tiene definidos en su propio prompt: `INPUT_TYPES`, `OUTPUT_DIR`, `ARTIFACT_TEMPLATE`, y `VERIFICATION_CHECKLIST`.

Seguí estos pasos EN ORDEN. Ninguno es opcional.

## Paso 1 — Identificar el input y cargarlo

Detectá qué tipo de input tenés según tu lista `INPUT_TYPES`. Cada tipo define cómo cargar el contexto:

- **Archivo en disco** (`.claude/docs/`): leélo completo con Read.
- **Texto del usuario en el chat** (brief informal): repetí en 2-3 líneas lo que entendiste, identificá qué áreas del sistema SAV toca, y confirmá antes de avanzar.
- **Sin input**: pedile al usuario que describa lo que quiere.

Si no es claro qué procesar, listá los archivos disponibles y pedí confirmación. NO empieces a trabajar sin haber leído o capturado el input completo.

## Paso 2 — Cargar contexto del dominio

1. CLAUDE.md raíz (si existe).
2. CLAUDE.md de backend y frontend (si existen y son relevantes).
3. `.claude/knowledge/veterinary-domain.md` si el input toca protocolos, alertas, planes sanitarios.
4. `.claude/knowledge/regulations-by-country.md` si el input toca multi-país.
5. Cualquier doc relevante en `.claude/docs/reference/`.

## Paso 3 — Analizar y resolver ambigüedades

El detalle de este paso es específico de cada agente (preguntas cerradas, análisis de impacto, decisiones técnicas). Seguí las instrucciones de tu prompt para este paso.

## Paso 4 — ESCRIBIR EL ARCHIVO (OBLIGATORIO)

Tu output principal es el ARCHIVO en disco, no la respuesta en el chat. Usá Write para crear el archivo en tu `OUTPUT_DIR` con la estructura de tu `ARTIFACT_TEMPLATE`.

Para elegir el nombre del archivo:
1. Listá los archivos existentes en `OUTPUT_DIR` con Glob.
2. Seguí la convención de nombres definida en tu prompt.

## Paso 5 — Verificar y reportar

Después de escribir el archivo:
1. Confirmá con Read que se escribió correctamente.
2. Respondé con un resumen breve (5-10 líneas) que incluya OBLIGATORIAMENTE:
   - Ruta exacta del archivo generado
   - Métricas clave del artefacto (definidas en tu prompt)
   - Próximo paso sugerido (qué agente invocar después)

## Checklist final (validación interna antes de cerrar)

Verificá tu `VERIFICATION_CHECKLIST` completa. Si algún check falla, corregilo antes de terminar. No des por terminada tu respuesta hasta que todos pasen.

## Reglas de comportamiento compartidas

- SIEMPRE escribí en castellano. El equipo es hispanohablante.
- SÉ BREVE en el chat. No enseñes el dominio al usuario, él ya lo conoce.
- Si el input es vago o incompleto, decilo claramente y preguntá — no lo "salves" inventando.
- NUNCA dejes al usuario sin artefacto. Siempre cerrá con un archivo escrito en disco.
