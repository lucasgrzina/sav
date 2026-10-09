# TKT-009 - Borrado de HealthPlanTemplate global en uso devuelve error genérico

## Tipo
Bug

## Contexto
Detectado como efecto secundario durante el trabajo de TKT-008 (plantillas de plan sanitario propias del vet), no reportado por el usuario final. Al intentar borrar una `HealthPlanTemplate` GLOBAL (`vet_id` NULL) que ya generó al menos un `EstablishmentHealthPlan`, la base de datos rechaza el DELETE por la FK `restrictOnDelete()` de `establishment_health_plans.health_plan_template_id`. El flujo admin no tiene manejo específico para ese caso, así que el usuario recibe un mensaje de error genérico e inespecífico en vez de uno claro indicando por qué no puede borrar la plantilla.

## Estado actual
- `AdminHealthPlanTemplateController::destroy` llama a `HealthPlanTemplateService::destroy()`, que ejecuta el delete sin ningún chequeo previo de uso.
- La excepción de integridad referencial NO llega sin capturar al cliente: `ResponseHelper::makeFromException` ya tiene un handler genérico de `QueryException` (`mapDbError`) que mapea los códigos MySQL 1451/1452 (FK violation) a **422** con el mensaje fijo `"No se puede completar la operación por restricciones de integridad."`. Es decir, hoy el status code ya es correcto (422), pero el mensaje es genérico y no le dice al vet/admin que la causa es "esta plantilla tiene planes instanciados".
- El patrón correcto ya existe para plantillas de vet: `HealthPlanTemplateService::destroyOwnedByVet()` hace un pre-check con `HealthPlanTemplateRepositoryEloquent::hasInstantiatedPlans()` y lanza `HealthPlanTemplateLockedException` si corresponde. `VetHealthPlanTemplateController::destroy` captura esa excepción explícitamente y devuelve `{reason: 'template_locked'}` + mensaje específico en 422.
- Ese pre-check NO se usa en el path admin/global porque TKT-008 (DEC-NEG-04) excluyó explícitamente a las plantillas globales del bloqueo de edición/borrado — "su ciclo de vida no cambia". Pero la FK de la tabla sí actúa igual para plantillas globales en uso: el bloqueo de hecho ya existe a nivel de base de datos, solo falta la capa de manejo de error para un mensaje claro.
- Importante: el mensaje actual de `HealthPlanTemplateLockedException` ("La plantilla ya generó planes sanitarios instanciados y no puede editarse ni eliminarse.") no es reutilizable tal cual para este caso — en plantillas globales la EDICIÓN sigue permitida, solo el DELETE choca con la FK. Reusar la excepción sin ajustar el mensaje introduciría información incorrecta.

## Decisiones tomadas (no negociables)

### DEC-NEG-01: Alcance del fix
El fix se limita al path de borrado de plantillas globales (`AdminHealthPlanTemplateController::destroy` / `HealthPlanTemplateService::destroy()`). No se extiende el alcance a bloquear la edición de plantillas globales en uso, ni a modificar el ciclo de vida definido en TKT-008 DEC-NEG-04 (que aplica solo a plantillas de vet). Editar una plantilla global en uso sigue permitido.

### DEC-NEG-02: Mensaje al usuario
El usuario final debe recibir un mensaje específico tipo "esta plantilla está en uso y no se puede eliminar" (con el detalle exacto a definir por el arquitecto), en vez del mensaje genérico actual de `mapDbError`. El status code correcto ya es 422 y debe mantenerse.

## Decisiones que el arquitecto debe tomar

### A definir 1: Mecanismo de detección
Definir si se agrega un pre-check explícito en `HealthPlanTemplateService::destroy()` reutilizando `HealthPlanTemplateRepositoryEloquent::hasInstantiatedPlans()` (evita el roundtrip fallido a la FK, consistente con el patrón ya usado en `destroyOwnedByVet()`), o si se prefiere mantener el catch de `QueryException` pero afinando el mensaje específico para este caso en `mapDbError` (evalúa si `mapDbError` puede/debe distinguir por tabla/constraint o si conviene mantenerlo genérico y resolver la especificidad en la capa de excepción de dominio).

### A definir 2: Excepción a usar
Definir si se reutiliza `HealthPlanTemplateLockedException` ajustando su mensaje para que sea válido tanto para el caso vet (bloqueo total edit+delete) como para el caso global (solo delete), parametrizando el mensaje según contexto, o si conviene crear una excepción de dominio nueva y más específica para "plantilla en uso, no se puede eliminar" que no implique bloqueo de edición.

### A definir 3: Nivel de captura
`HealthPlanTemplateService::destroy()` es compartido conceptualmente (aunque hoy solo lo llama el path admin). Definir si el chequeo va en el service (protege cualquier llamador futuro) o solo en el controller admin, y si conviene alinear el shape de la respuesta de error (`reason: 'template_in_use'` o similar) con el que ya usa el path vet (`reason: 'template_locked'`) para consistencia de contrato API.

## Restricciones
- No modificar el ciclo de vida de plantillas globales definido en TKT-008 (DEC-NEG-04 no aplica a global; edición sigue permitida).
- Mantener el status code 422 ya presente en el comportamiento actual (no regresionar a 500 ni a otro código).
- GUID como identificador en rutas y payloads, sin tocar ese contrato.
- No introducir un nuevo endpoint; el fix es de manejo de excepción/mensaje dentro del flujo de destroy existente.

## Investigación previa que el arquitecto debe hacer
1. Confirmar si `HealthPlanTemplateRepositoryEloquent::hasInstantiatedPlans()` es reutilizable tal cual para el path global o si conviene un método separado (mismo query, `EstablishmentHealthPlan::where('health_plan_template_id', $templateId)->exists()`, sin distinción de ownership).
2. Revisar todos los callers actuales de `HealthPlanTemplateService::destroy()` para confirmar que el cambio no afecta comportamiento esperado en otro flujo.
3. Revisar el shape de respuesta de error que ya usa `VetHealthPlanTemplateController::destroy` (`{reason: 'template_locked'}`) para decidir si el path admin debe converger a un contrato de error consistente.
4. Confirmar cobertura de tests existente sobre `AdminHealthPlanTemplateController::destroy` y `HealthPlanTemplateService::destroy()` para saber qué actualizar/agregar.

## Output esperado
Plan en `.claude/docs/plans/TKT-009-borrado-plantilla-global-en-uso-plan.md`
