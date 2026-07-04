# Workflow de generación de tests

Template compartido por los agentes `backend-tester` y `frontend-tester`. El agente que lee este archivo ya tiene definidos en su propio prompt: `TEST_FRAMEWORK`, `TEST_STRUCTURE`, `MOCK_SETUP`, `MANDATORY_TESTS`, `RUN_CMD`, y `FINAL_CHECKLIST`.

Seguí estos pasos EN ORDEN. Ninguno es opcional.

## Paso 1 — Cargar contexto

1. Leé los archivos a testear: controlador/componente, servicio/composable, rutas, requests/validators.
2. Buscá si existe factory/mock setup existente en el proyecto.
3. Mirá los tests existentes para entender el estilo del proyecto (Glob `**/*.test.*` o `**/tests/**`).
4. Si existe un archivo de setup de tests, leélo.

## Paso 2 — Crear factory/mocks si no existen

Si no hay factory (backend) o mock setup (frontend) para el módulo, creálos primero siguiendo tu `MOCK_SETUP`.

## Paso 3 — Generar tests

Creá los archivos de test en la ubicación definida por tu `TEST_STRUCTURE`.

### Tests obligatorios

Seguí tu lista `MANDATORY_TESTS` para cada endpoint/componente/composable. No omitas ningún test obligatorio aunque el módulo parezca simple.

### Formato de cada test

Cada test debe:
- Tener un nombre descriptivo que diga qué verifica
- Preparar el escenario (arrange)
- Ejecutar la acción (act)
- Verificar el resultado (assert)

## Paso 4 — Ejecutar los tests

Ejecutá tu `RUN_CMD` con Bash.

Si algún test falla:
- Si el error es del test → corregí el test.
- Si el comportamiento real es incorrecto → reportalo como bug en el código.
- NUNCA ajustes un assertion para que pase si el comportamiento es incorrecto.

## Paso 5 — Reportar

Respondé con:
- Lista de archivos de test creados
- Cantidad de tests generados (por categoría)
- Resultado de la ejecución
- Si algún test falló: qué falla y si es bug en código o en el test
- Cobertura aproximada: qué queda sin test

## Checklist final

Verificá tu `FINAL_CHECKLIST` completa. Si algún check falla, corregilo antes de terminar.

## Reglas de comportamiento compartidas

- NUNCA ajustes un assertion para que pase — si falla, es información valiosa.
- NUNCA testees detalles de implementación — testéa lo que ve y puede hacer el usuario.
- ESCRIBÍ EN CASTELLANO en tus respuestas. El código de tests en inglés (o consistente con el estilo del proyecto).
