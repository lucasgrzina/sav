# Spec funcional: Programa (Program) — para diseño del sistema nuevo

Documento de referencia funcional/técnica de todo lo que hoy hace la funcionalidad "Programa" en SAV, para que el analista del proyecto nuevo pueda diseñar su equivalente. Incluye el modelo de datos actual, las reglas de negocio, los flujos, y el diseño adoptado para el requerimiento nuevo de **múltiples grupos de animales por programa, cada uno con su propia fecha objetivo** (§8), que hoy el sistema NO soporta (hoy es un único grupo opcional con una única fecha a nivel de todo el programa).

**Alcance de este documento:** solo el Programa como entidad (datos, flujos de alta/edición/cancelación, grupos y fechas objetivo). El motor de generación/envío de alertas a partir de estas fechas es intencionalmente **otra especificación aparte** — acá se deja mencionado dónde engancha, pero no se diseña.

---

## 1. Qué es un Programa

Un **Programa** es la instancia concreta de aplicación de una **Técnica** veterinaria (ej. protocolos reproductivos, sanitarios, etc.) a un **Cliente** en un **Establecimiento**, siguiendo un **Protocolo** específico (una plantilla de tareas y alertas asociada a esa técnica). Es la entidad central del dominio SAV: conecta Cliente → Establecimiento → Técnica/Protocolo, con uno o más **Managers** (usuarios responsables).

Un Programa se compone de **uno o más Objetivos** (§8): cada Objetivo es una fecha objetivo concreta, opcionalmente asociada a un grupo de animales. Un programa simple (sin desglose por grupo) tiene un único Objetivo sin grupo; un programa que trabaja con distintos grupos de animales en distintas fechas (ej. donantes vs receptoras en transferencia embrionaria) tiene un Objetivo por grupo, cada uno con su propia fecha.

Un Programa por sí mismo no "hace" nada operativamente además de servir de contenedor: su valor es disparar automáticamente una serie de **Alertas** (recordatorios por WhatsApp) a los managers en las fechas calculadas a partir del Protocolo.

---

## 2. Entidades y relaciones

```
Client ─┐
        ├─< Program >─┬─ Establishment
        │              ├─ Technique (vía Protocol)
        │              ├─ Protocol ──< ProtocolTask (plantilla, hoy sin uso activo — ver §7)
        │              │           └─< ProtocolAlert (plantilla de alertas)
        │              ├─ Vet (tenant)
        │              ├─< managers (N usuarios, pivot program_manager)
        │              ├─< targets (1..N ProgramTarget — DISEÑO NUEVO, ver §8)
        │              │        └─ cada target: target_date + animals (0..N Animal, vía pivot program_target_animal —
        │              │                         Animal es entidad persistente, compartida con HealthPlan, sin tabla de "grupo")
        │              ├─< tasks (Tasks — tabla legacy, ver §7)
        │              └─< alerts (vía model_id/model_class = Program, ver doc de alertas — el anclaje
        │                          de fecha por target es tema del futuro spec del motor de alertas, va a haber cambios en estructura de tablas)
```

> Nota: `group_id` y `target_date` **dejan de ser columnas de `programs`** en el diseño nuevo — se mueven a la tabla `program_targets` (§8), porque la fecha objetivo ahora es una propiedad del grupo/objetivo, no del programa como un todo.

### 2.1 Tabla `programs`
| Campo | Tipo | Notas |
|---|---|---|
| `client_id` | FK → clients, cascade | obligatorio |
| `establishment_id` | FK → establishments, cascade | obligatorio |
| `technique_id` | FK → techniques, cascade | obligatorio |
| `protocol_id` | FK → protocols, cascade | obligatorio |
| `state` | string | ver §4 — en la práctica solo se persiste `'cancelled'`, el resto se **calcula** en runtime |
| `comments` | text nullable | |

Ya no tiene `group_id` ni `target_date` propios — ver `program_targets` en §8.

Atributo calculado `name` (no persistido, hoy): `"{establecimiento} - {protocolo} ({técnica padre si aplica}) - {fecha objetivo dd-mm-YYYY} - {nombre del grupo si existe}"`. Con múltiples objetivos esta fórmula deja de tener una única fecha/grupo para mostrar — queda como **decisión de UI abierta** en §8 (ej. mostrar la fecha más próxima, o un badge "N grupos").

### 2.2 Tabla `program_manager` (pivot)
`program_id` + `user_id`, único por par. Un Programa tiene N managers (usuarios responsables que reciben las alertas y gestionan el programa desde la app). No tiene rol propio en la tabla — el rol del manager se resuelve por su rol de sistema (Spatie) al momento de mostrarlo. En el nuevo sistema analizar si deberia ser UserProfile (ya cuenta con user_id y role_id)

### 2.3 Tabla `techniques`
| Campo | Notas |
|---|---|
| `name` | |
| `target_date_name` | nombre a mostrar de la fecha objetivo (ej. "Fecha de servicio", varía según técnica) |
| `type` | string libre, default `'technique'` |
| `parent_id` | FK a `techniques` — permite técnicas jerárquicas (una técnica "hija" de otra) |
| `protocols_name` | nombre a mostrar para "protocolo" en el contexto de esta técnica |

Una Técnica puede tener sub-técnicas (`children()`/`parent()`). El filtro de Programas por Técnica en el panel Vet incluye automáticamente los hijos de la técnica seleccionada.

**Sobre grupos:** se definió que **todas las Técnicas aceptan de 1 a N grupos por igual** (no hay una técnica que "requiera" grupos y otra que no permita varios) — no hace falta ningún campo de configuración en `Technique` para esto. Ver diseño adoptado en §8. Un grupo puede estar compuesto por un solo animal o muchos

### 2.4 Tabla `protocols`
Plantilla asociada a una Técnica (`technique_id`) y a un Vet (tenant) o global (`vet_id` null). Tiene soft deletes. Contiene:
- `tasks()` → `ProtocolTask` (plantilla de tareas, ver §7 )
- `alerts()` → `ProtocolAlert` (plantilla de alertas )
En el nuevo sistema, las alertas estan vinculadas a las tareas. Antes eran sueltas.

### 2.5 Tabla `protocol_task_alerts` (plantilla de alertas de un protocolo)


### 2.6 Animales — SIN tabla de grupos (rediseñado, ver §8.3)
- **Sistema actual:** `animals_groups` (`name`, `establishment_id`) + `animals` (`rp_donor`, `animals_group_id`). `Program.group_id` → `belongsTo AnimalsGroup` (uno solo, opcional). Al crear/editar el programa, si se completa `group_name` + `animals_rp` (string de RPs separados por coma), se crea o reemplaza completamente un grupo de animales asociado 1:1 al programa.
- **Sistema nuevo:** se elimina la entidad "grupo" — agrupar es un concepto de uso diario (qué animales le tocan a qué Objetivo/fecha), no una entidad de negocio persistente. `Animal` pasa a ser una **entidad estable** (identificable, reutilizable en el tiempo) que se asocia directamente a cada `ProgramTarget` mediante una tabla pivot. Detalle completo en §8.3.

### 2.7 Tabla `protocol_tasks` (instancia de tareas del programa — ver §7)

---

## 3. Roles involucrados

- **Vet** (veterinario/tenant): crea, edita y cancela programas; ve todos los programas de su tenant.
- **Manager** (uno o más `User`, típicamente con rol de cliente): responsable(s) asignado(s) al programa, reciben las alertas por WhatsApp y pueden confirmarlas si `require_confirmation`.
- **Cliente** (dueño del establecimiento): puede consultar (no gestionar) los programas de su `client_id` vía API.

---

## 4. Estados del Programa

`state` es un **atributo calculado** (accessor en el modelo), no una máquina de estados persistida — solo `'cancelled'` se guarda realmente en la columna:

| Estado mostrado | Cómo se calcula |
|---|---|
| `cancelled` | valor persistido en `state` — el único estado "real" en base |
| `pending` | ninguna de las alertas del programa tiene `delivered_at` seteado |
| `completed` | **todas** las alertas del programa tienen `delivered_at` seteado |
| `In progress` | hay alertas entregadas pero no todas |

Adicional: atributo calculado `editable` — un programa deja de ser editable si está cancelado, o si la primera tarea (`tasks`, tabla legacy — ver §7, en la práctica siempre vacía) ya venció. **Ojo:** por el problema de §7, esta regla de "editable" probablemente nunca se activa hoy en producción salvo por cancelación.

**Con múltiples Objetivos (§8):** tanto `state` como `editable` hoy se apoyan en conceptos de una sola fecha/una sola tanda de alertas por Programa. Al pasar a N Objetivos con N fechas independientes, estas reglas van a necesitar redefinirse (¿el programa está "completed" cuando se completan todos los objetivos, o alguno? ¿es editable mientras algún objetivo no venció?). Se deja pendiente para cuando se defina el motor de alertas, ya que ahí es donde vive el concepto de "entregado" del que depende `state` hoy.

---

## 5. Flujos

### 5.1 Alta de Programa

**Comportamiento actual (a modernizar):**
**Disparadores:** `POST /api/v1/programs` (`ProgramsController@store`) y Filament `CreateProgram` (panel Vet).

Validación (API): `client_id`, `establishment_id`, `technique_id`, `protocol_id`, `target_date`, `state` (`Pending`|`Completed`), `comments` (opcional), `managers` (obligatorio, lista de user ids), `group_name` (opcional), `animals_rp` (opcional).

Pasos (`CreateProgramAction::execute` + `UpsertProgramAlertsAction::execute`):
1. Si viene `group_name`, crea un `AnimalsGroup` nuevo en el establecimiento indicado, y un `Animal` por cada RP en `animals_rp` (split por coma).
2. Crea el `Program`.
3. Asocia los managers (`program_manager`).
4. **Genera las alertas**: por cada `ProtocolAlert` del protocolo elegido, calcula `send_at` = `target_date` ± `days_offset` a la hora `time`; si esa fecha ya pasó, se **descarta silenciosamente** esa alerta (no se crea, no hay log). Crea el registro `Alert` correspondiente (`notification_class = ProgramTaskNotification`) y le asigna como recipients los managers cuyo rol esté en `protocol_alert.roles`.
5. Notifica inmediatamente (sin pasar por `Alert`/cron) a todos los managers con `ProgramCreatedNotification` (WhatsApp vía Twilio, plantilla de "programa creado").

**Diseño nuevo (§8):** el formulario de alta pasa a tener un único campo `target_date` a nivel del programa **por N filas de "Objetivo"** — cada fila con: nombre de grupo (opcional), lista de RPs de animales (opcional, solo si hay grupo) y su propia fecha objetivo. Como mínimo debe haber 1 fila. El paso 4 (generación de alertas) se recalcula **por Objetivo** en vez de una sola vez por Programa — el detalle exacto de ese cálculo queda para el spec del motor de alertas.

### 5.2 Edición de Programa

**Comportamiento actual (a modernizar):**
**Disparadores:** `PUT /api/v1/programs/{program}` y Filament `EditProgram`.

`UpdateProgramAction::execute`:
1. Actualiza los campos del programa.
2. Si el programa ya tenía grupo, **reemplaza completamente** sus animales (borra todos y recrea desde `animals_rp`) y actualiza nombre/establecimiento del grupo. (No contempla alta de grupo si el programa no tenía uno — inconsistencia respecto al alta, a resolver en el diseño nuevo).
3. Re-sincroniza managers (`sync`).
4. **Importante:** `UpsertProgramAlertsAction::execute()` está **comentado** — es decir, **editar un programa NO regenera sus alertas**, aunque cambie `target_date` o el protocolo. Las alertas ya creadas al alta quedan como estaban. Esto es una limitación real y muy probablemente un bug/deuda técnica, no una decisión de negocio — confirmar con el cliente si el sistema nuevo debe corregir esto (regenerar alertas cuando cambian fecha/protocolo).
5. Hay un bloque de notificación de "programa actualizado" pero está **comentado/sin implementar** (`ProgramUpdated` no existe como clase).

**Diseño nuevo (§8):** la edición pasa a operar sobre la colección de Objetivos del programa: permitir agregar Objetivos nuevos, editar la fecha/grupo/animales de uno existente, y eliminar un Objetivo (con la salvedad de no poder dejar el programa con 0 Objetivos). Queda como decisión de UI/negocio si eliminar un Objetivo con alertas ya generadas debe cancelar esas alertas puntuales (tema a resolver junto con el motor de alertas). Igual que hoy, decidir si al editar el protocolo o cambiar fechas de un Objetivo se deben regenerar sus alertas (mismo punto pendiente que en el sistema actual, ítem 4 arriba).

### 5.3 Cancelación de Programa
**Disparadores:** `POST /api/v1/programs/{program}/cancel` y botón "Cancelar" en Filament.

`CancelProgramAction::execute`:
1. Marca `state = 'cancelled'`.
2. Borra **todas** las alertas pendientes o entregadas del programa (detach recipients + delete).
3. Crea una alerta nueva inmediata (`send_at = now()`) con `notification_class = ProgramCanceledNotification`, recipients = managers actuales. Esta se envía en el próximo tick del cron (máx. 1 minuto de demora).

### 5.4 Confirmación de alerta (para alertas con `require_confirmation`)
`POST /api/v1/tasks/{alert}/complete` → `ProgramsController@confirmAlert` (el nombre de ruta dice "tasks" pero opera sobre `Alert`, no sobre `Tasks` — nomenclatura inconsistente a limpiar en el rediseño). Simplemente setea `confirmed_at = now()` en la Alert. Sin restricción de que el usuario que confirma sea un recipient real de esa alerta — cualquiera con el id de la alerta puede confirmarla.

### 5.5 Listado y detalle
- `GET /api/v1/programs` — filtra por `vet_id` o `client_id` según el rol del usuario autenticado.
- `GET /api/v1/programs/{program}` — devuelve el programa con relaciones, más una vista "por día" (`days`) armada a partir de las **alertas** del programa (no de `tasks`), filtrando qué alertas puede ver el usuario (si no es Vet/Assistant, solo las que lo tienen como recipient), y los datos del grupo de animales (nombre + lista de RPs).
  - **Diseño nuevo:** el bloque `group` de la respuesta pasa a ser una lista `targets: [{ target_date, group_name, animals_rp[] }, ...]`, una entrada por Objetivo.

---

## 6. Alertas generadas por el Programa

Documentado en detalle en `docs/arquitectura-alertas-twilio.md`. Resumen para este spec:

| Notification | Cuándo | Recipients |
|---|---|---|
| `ProgramTaskNotification` | Una por cada `ProtocolAlert` del protocolo, al alta | managers con rol incluido en `protocol_alert.roles` |
| `ProgramCreatedNotification` | Inmediata al alta (no pasa por tabla `alerts`) | todos los managers |
| `ProgramCanceledNotification` | Inmediata al cancelar | managers al momento de la cancelación |

Todas se envían hoy por WhatsApp vía Twilio (Content API, plantillas pre-aprobadas). Ver el otro documento para el detalle de transporte, opt-out, y variables de cada plantilla.

---

## 7. Código legado / posiblemente muerto — no migrar tal cual sin confirmar

Detectado durante el relevamiento, importante para que el analista no lo tome como requerimiento activo:


- **Notificación "programa actualizado"**: referenciada en comentarios (`ProgramUpdated`) pero la clase no existe — no implementado, no confirmar como requerimiento salvo que el cliente lo pida.

- **Regeneración de alertas al editar programa**: comentada intencionalmente (`UpsertProgramAlertsAction::execute()` deshabilitada en `UpdateProgramAction`). Confirmar si el sistema nuevo debe regenerar alertas cuando cambia `target_date` o `protocol_id` de un programa existente — el comportamiento actual (no regenerar) probablemente no es deseable pero se desconoce si fue una decisión consciente o deuda técnica.

---

## 8. Diseño adoptado: Programa 1 → N Objetivos (grupo + fecha propia)

### 8.1 Motivación / feedback del cliente

El sistema actual asume que un Programa tiene **una sola fecha objetivo para todo el programa** y, como mucho, **un solo grupo de animales**. El cliente aclaró que un plan puede tener **0, 1 o N grupos de animales**, y que **cada grupo tiene su propia fecha objetivo dentro de ese mismo programa/protocolo** (ej. distintos grupos de donantes/receptoras que arrancan el mismo protocolo en fechas distintas).

Se evaluaron dos alternativas:
- **(A) Un Programa por grupo** — descartada: duplicaría cliente, establecimiento, técnica, protocolo, managers y comentarios por cada grupo, y cualquier operación de alto nivel sobre "el plan" (cancelar, listar, editar el protocolo) tendría que hacer fan-out sobre N filas en vez de ser una operación atómica.
- **(B) Un Programa único con N "Objetivos", cada uno con su propia fecha y grupo opcional** — **adoptada**. Todo lo compartido queda en una sola fila de `programs`; lo que varía por grupo (fecha, animales) vive en una entidad hija.

### 8.2 Regla de negocio resuelta

**Todas las Técnicas aceptan de 1 a N grupos por igual** — no existe una técnica que solo permita 1 ni una que exija más de 1; la cardinalidad es una decisión libre del usuario al cargar el programa, no una restricción de la Técnica. Esto simplifica el diseño: no hace falta ningún campo de configuración en `Technique`.

### 8.3 Modelo de datos nuevo

**a) `program_targets` ("Objetivo del Programa")**

| Campo | Tipo | Notas |
|---|---|---|
| `program_id` | FK → programs, cascade | obligatorio |
| `target_date` | date | **reemplaza** a `programs.target_date` — ahora vive acá, por objetivo |
| `timestamps` | | |

Ya **no tiene `group_id`** — no existe entidad "grupo" a la que apuntar (ver punto b). Si un objetivo no tiene animales asociados (caso simple, sin desglose), simplemente no tiene filas en la tabla pivot.

Reglas:
- Un `Program` tiene **como mínimo 1** `ProgramTarget` — nunca 0. El caso "sin desglose por grupo" de hoy se modela como **un único `ProgramTarget` sin animales asociados**, no como ausencia de la entidad. Esto hace que el caso simple sea literalmente `N=1` del caso general.
- `programs.group_id` y `programs.target_date` se eliminan de la tabla `programs` (ver §2.1).

**b) `animals` — entidad persistente y compartida (ya NO hay tabla `animals_groups`)**

El cliente aclaró que el sistema también necesita administrar animales como **mascotas para Planes Sanitarios (HealthPlan)**, no solo animales de rodeo identificados por RP en el contexto de Programas. Esto confirma que `Animal` debe ser una entidad única y persistente en el sistema, reutilizada por ambos módulos — **hoy, en el código actual, `HealthPlan` no tiene ninguna relación con `Animal`/`AnimalsGroup`** (revisado en el modelo `HealthPlan.php`), así que esto es funcionalidad genuinamente nueva, no una migración de algo existente.

| Campo | Notas |
|---|---|
| `client_id` | FK obligatoria — el animal pertenece a un Cliente, independientemente del contexto (rodeo o mascota) |
| `establishment_id` | FK nullable — relevante para animales de rodeo asociados a un establecimiento; probablemente no aplica a mascotas |
| identificación (nombre a definir) | hoy es `rp_donor` (RP del animal donante); para mascotas la identificación natural es otra (nombre propio, no un RP de rodeo) — **a definir junto con el spec de HealthPlan** qué campos de identificación/ficha necesita un animal-mascota (especie, raza, nombre, etc.) |
| `timestamps` | |

**No se incluyen en este documento** los campos específicos de ficha veterinaria de mascotas (especie, raza, fecha de nacimiento, etc.) — eso es tema del futuro spec de HealthPlan; acá solo se dimensiona que `Animal` debe poder representar ambos casos (rodeo con RP vs. mascota con nombre) sin duplicar la entidad.

**c) `program_target_animal` (pivot N:N)**

| Campo |
|---|
| `program_target_id` FK |
| `animal_id` FK |

Un `Animal` puede estar asociado a **múltiples** `ProgramTarget` a lo largo del tiempo (mismo animal en distintos programas/protocolos en distintas fechas) — esto da trazabilidad histórica real por animal, cosa que el modelo actual (`animals` recreados/borrados en cada edición de grupo) no tiene.

### 8.4 Impacto en los flujos (resumen — detalle en §5.1/5.2)

- **Alta:** el formulario pasa de "1 fecha + 1 grupo opcional" a "N filas, cada una con fecha + selección de animales existentes y/o alta de animales nuevos", mínimo 1 fila. Al no haber tabla de grupo, seleccionar animales para un objetivo es simplemente poblar el pivot `program_target_animal` — no hay que crear/nombrar un "grupo" contenedor.
- **Edición:** debe soportar agregar/editar/quitar objetivos individuales, y agregar/quitar animales de un objetivo (sync del pivot) sin borrar y recrear los `Animal` como hace hoy con `animals_rp` — el animal es persistente, solo cambia su asociación al objetivo.
- **Detalle (`GET /programs/{id}`):** el bloque de grupo pasa a ser una lista de objetivos, cada uno con su lista de animales (por su identificación, ya no por "nombre de grupo").
- **Nombre calculado (`name`) y filtros de fecha en listados:** al no haber una única fecha por programa, hay que definir qué mostrar en un listado (ej. fecha del objetivo más próximo, o un badge "N objetivos") — **decisión de UI abierta**, no resuelta en este documento.

### 8.5 Explícitamente fuera de alcance de este documento

Va en el spec del motor de alertas:
- Cómo se recalculan y anclan las alertas (`ProtocolAlert` → `Alert`) por objetivo en vez de por programa.
- Qué pasa con `Program.state`/`editable` (hoy derivados de si las alertas fueron entregadas) cuando hay N objetivos con N tandas de alertas independientes.
- Qué variables/plantillas de Twilio necesitan referenciar a qué objetivo/animales específicos.

Va en el futuro spec de HealthPlan (o en un spec transversal de "Animal" si termina siendo compartido por ambos):
- Campos de ficha veterinaria del animal-mascota (especie, raza, nombre, fecha de nacimiento, dueño si difiere del Cliente, etc.).
- Cómo HealthPlan pasa a referenciar `Animal` — hoy no lo hace en absoluto (ver §8.3.b).
- Si la identificación por RP (rodeo) y por nombre (mascota) conviven en una sola tabla `animals` con campos opcionales según tipo, o si conviene un campo `type` que determine qué datos aplican.

### 8.6 Migración de datos existentes

- Cada `Program` actual con `group_id`/`target_date` propios se migra a **un único `ProgramTarget`** con su `target_date`.
- Cada `AnimalsGroup` existente se "disuelve": sus `Animal` pasan a existir de forma independiente (conservando su `rp_donor` e `id`), y se crea una fila en `program_target_animal` por cada animal, apuntando al `ProgramTarget` migrado de ese programa. La tabla `animals_groups` no tiene equivalente en el modelo nuevo — se da de baja tras la migración.
