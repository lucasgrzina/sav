# Workflow de revisión QA

Template compartido por los agentes `qa-backend` y `qa-frontend`. El agente que lee este archivo ya tiene definidos en su propio prompt: `SCOPE_PATTERN`, `RULES_CHECKLIST`, `CROSS_CHECKS`, `VERIFY_CMD`, y `OUTPUT_PREFIX`.

Seguí estos pasos EN ORDEN. Ninguno es opcional.

## Paso 1 — Identificar el scope de la revisión

Según el input del usuario:
- **Nombre de módulo** (ej: `Establishment`): revisá todos los archivos del módulo según tu `SCOPE_PATTERN`.
- **Path a archivo específico**: revisá ese archivo solamente.
- **Directorio**: revisá todos los archivos relevantes en ese directorio.

Si no es claro qué revisar, pedí confirmación.

## Paso 2 — Leer y analizar cada archivo

Para cada archivo en scope:
1. Leélo completo con Read.
2. Verificá cada regla de tu `RULES_CHECKLIST` que aplique a ese tipo de archivo.
3. Por cada violación encontrada, anotá:
   - **Regla violada** (ej: C-01, M-03, m-05)
   - **Archivo y número de línea**
   - **Snippet exacto** del código problemático
   - **Corrección exacta** (cómo debe quedar)

## Paso 3 — Verificaciones cruzadas

Ejecutá cada verificación de tu lista `CROSS_CHECKS`. Estas son verificaciones de consistencia entre archivos (ej: Resource vs Controller, Zod vs FormRequest).

## Paso 4 — Verificación técnica

Si tu prompt define un `VERIFY_CMD`, ejecutalo con Bash e incluí el resultado en el reporte. Si falla, los errores tienen prioridad — son críticos aunque no estén en el checklist.

## Paso 5 — ESCRIBIR EL REPORTE (OBLIGATORIO)

Tu output principal es el ARCHIVO en disco, no la respuesta en el chat. Usá Write para crear:

`.claude/docs/reviews/{OUTPUT_PREFIX}-{nombre}-{fecha}.md`

Estructura OBLIGATORIA:

```
# QA Review — {STACK_LABEL}: {Nombre del módulo/archivo}
Fecha: {fecha}
Scope: {qué se revisó}

## Resumen ejecutivo
- Críticos: N
- Mayores: N
- Menores: N
- Estado: BLOQUEANTE / CON OBSERVACIONES / APROBADO

## Problemas críticos (bloquean merge)

### [C-0X] — {descripción corta}
**Archivo**: `path/al/archivo` línea N
**Código actual**:
\`\`\`
// el código problemático
\`\`\`
**Corrección**:
\`\`\`
// cómo debe quedar
\`\`\`

## Problemas mayores
(misma estructura que críticos)

## Problemas menores
(misma estructura, puede ser solo texto si es obvio)

## Verificaciones cruzadas
(lista de cada CROSS_CHECK con resultado OK / PROBLEMA)

## Archivos revisados
- `path/archivo1`
- `path/archivo2`
```

## Paso 6 — Reportar en el chat

Después de escribir el archivo, respondé con:
- Ruta exacta del reporte generado
- Conteo de críticos / mayores / menores
- Estado general: BLOQUEANTE (hay críticos), CON OBSERVACIONES (hay mayores), APROBADO (solo menores o nada)
- Los 3 problemas más importantes, en una línea cada uno
- Próximo paso sugerido

## Reglas de comportamiento

- SÉ ESPECÍFICO: "línea 47 de UserController.php tiene lógica de negocio" es bueno; "el controller tiene problemas" es malo.
- SIEMPRE incluí snippet del problema + corrección exacta. No describas problemas en abstracto.
- NO señales estilo subjetivo (nombres de variables, longitud de métodos) — solo las reglas del checklist.
- Si el código está correcto, decilo. Un "sin problemas" es válido y valioso.
- ESCRIBÍ EN CASTELLANO en el reporte y en el chat.
- Si encontrás una violación que no está en tu checklist, agregala como "Observación adicional" pero no la inventés como regla oficial.
