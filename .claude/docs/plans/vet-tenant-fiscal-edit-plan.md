# Plan técnico: Habilitar edición de datos fiscales en self-service de veterinaria (tenant)

## Input procesado
Brief informal del usuario (con diagnóstico previo ya validado contra el código real). No hay spec funcional ni ticket asociado.

## Resumen ejecutivo
Actualmente el formulario de self-service (`origin="tenant"`) de `VetForm.vue` solo permite editar `name`, `pdf_title`, `pdf_subtitle`, `contacts`. Se habilita también la edición de `document_type_guid`, `tax_id` y `registration_number` para el propio tenant, reutilizando el mismo endpoint (`PUT /v1/vets/{guid}` → `VetController::update`) que ya soporta estos campos sin restricción de origen. El país (`country_guid`) permanece exclusivo de admin y no editable post-creación — no se toca. Backend sin cambios. Todo el trabajo es frontend: validator, template y lógica de `VetForm.vue`.

## Decisiones tomadas

DEC-01 — Reglas de validación del schema tenant
  Decisión: `vetTenantUpdateSchema` copia exactamente las reglas de `document_type_guid`, `tax_id` y `registration_number` de `vetUpdateSchema` (todas `.optional()`, mismos mensajes y límites).
  Justificación: el backend (`UpdateVetRequest`) ya valida estos campos como `sometimes`/`nullable` sin distinguir origen — el FE debe reflejar el mismo contrato. Usar el mismo nivel de opcionalidad evita divergencia entre el schema admin y tenant para campos idénticos.
  Alternativa descartada: hacerlos obligatorios en tenant. Se descarta porque el backend los trata como opcionales y no hay ningún requerimiento de negocio que diga que el tenant deba re-enviar estos datos en cada edición.

DEC-02 — Fuente del país para `useDocumentTypes` en modo tenant
  Decisión: computed `documentTypesCountryGuid` que devuelve `country_guid.value` si `origin === 'admin'`, o `props.initialValues?.country?.guid ?? ''` si `origin === 'tenant'`.
  Justificación: para tenant el campo reactivo `country_guid` del form nunca se popula (el país no es editable ni se incluye en el schema tenant), así que hay que leerlo directo de los datos del vet ya cargado. `VetController::show()` (self-service) ya hace eager-load de `country` y `VetResource` expone `country.guid`, confirmado en código.
  Alternativa descartada: agregar `country_guid` al `setValues` de tenant y usarlo igual que admin. Se descarta porque `country_guid` no forma parte de `vetTenantUpdateSchema` (el campo no debe ser editable ni enviarse en el payload), y meterlo en `setValues` sin estar en el schema es un uso incorrecto de vee-validate que además arriesga que termine viajando en el payload por error futuro.

DEC-03 — Corrección del `:disabled` del combo "Tipo de documento" (hallazgo no cubierto por el diagnóstico original)
  Decisión: cambiar `:disabled="!country_guid"` (línea 186 actual) por `:disabled="!documentTypesCountryGuid"`.
  Justificación: verificado en código — `country_guid` es un campo del form que para tenant JAMÁS se popula (no está en `vetTenantUpdateSchema`, no se setea en `setValues`). Si no se corrige este atributo, el combo de tipo de documento queda permanentemente deshabilitado para el tenant aunque el bloque ya sea visible, dejando la funcionalidad rota en la práctica. `documentTypesCountryGuid` (DEC-02) sí resuelve correctamente a un valor no vacío en ambos orígenes.
  Alternativa descartada: ninguna — es un bug bloqueante que hay que corregir sí o sí para que el pedido funcione, no una alternativa de diseño.

DEC-04 — Bloque "País" permanece admin-only
  Decisión: no tocar el `<template v-if="origin === 'admin'">` que envuelve el `a-form-item` de "País" (líneas 146-167).
  Justificación: coincide con la regla de negocio confirmada: ni el propio admin puede editar el país post-creación (`:disabled="mode === 'edit'"`), y el pedido del usuario es habilitar tipo de documento / CUIT / matrícula, no el país.

## Cambios en BACKEND

Sin cambios. Verificado en código:
- `back/app/Http/Requests/Vets/UpdateVetRequest.php` — `document_type_guid` (`sometimes`), `tax_id` (`sometimes`), `registration_number` (`nullable`) ya soportan envío parcial sin distinguir quién llama.
- `back/app/Http/Controllers/V1/VetController.php:31` (`update`) es el endpoint self-service (usa `current_vet` del request, no recibe `$guid` de admin) y ya usa `UpdateVetRequest` + `VetService::update()` sin restricción de campos por rol.
- `front/src/modules/vets/api/vets.api.ts:62` (`updateVetTenantApi`) pega contra `PUT /v1/vets/${guid}`, que es exactamente esta ruta self-service.

## Cambios en FRONTEND

### Archivos a modificar

#### `front/src/modules/vets/validators/vet.validator.ts`

**Cambio:** extender `vetTenantUpdateSchema` (actualmente líneas 87-102) con `document_type_guid`, `tax_id`, `registration_number`, copiados de `vetUpdateSchema`.

**Antes:**
```ts
export const vetTenantUpdateSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(150, 'Máximo 150 caracteres'),
  pdf_title: z
    .string()
    .max(200, 'Máximo 200 caracteres')
    .nullable()
    .optional(),
  pdf_subtitle: z
    .string()
    .max(200, 'Máximo 200 caracteres')
    .nullable()
    .optional(),
})
```

**Después:**
```ts
export const vetTenantUpdateSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(150, 'Máximo 150 caracteres'),
  document_type_guid: z
    .string()
    .min(1, 'El tipo de documento es requerido')
    .optional(),
  tax_id: z
    .string()
    .min(1, 'El CUIT/identificador fiscal es requerido')
    .max(30, 'Máximo 30 caracteres')
    .optional(),
  registration_number: z
    .string()
    .max(50, 'Máximo 50 caracteres')
    .nullable()
    .optional(),
  pdf_title: z
    .string()
    .max(200, 'Máximo 200 caracteres')
    .nullable()
    .optional(),
  pdf_subtitle: z
    .string()
    .max(200, 'Máximo 200 caracteres')
    .nullable()
    .optional(),
})
```

No tocar `vetCreateSchema` ni `vetUpdateSchema`. `pdf_title`/`pdf_subtitle` de `vetTenantUpdateSchema` quedan en 200 caracteres (no 150) porque así estaban antes — no es parte de este cambio.

---

#### `front/src/modules/vets/components/forms/VetForm.vue`

**Cambio 1 — import de tipos (línea 8):**

Antes:
```ts
import type { VetCreateForm, VetUpdateForm } from '../../validators/vet.validator'
```

Después:
```ts
import type { VetCreateForm, VetUpdateForm, VetTenantUpdateForm } from '../../validators/vet.validator'
```

**Cambio 2 — fuente del país para `useDocumentTypes` (reemplaza líneas 53-55, ver DEC-02 y DEC-03):**

Antes:
```ts
const { data: documentTypesData, isLoading: isLoadingDocTypes } = useDocumentTypes(
  computed(() => (country_guid.value as string) ?? ''),
)
```

Después:
```ts
const documentTypesCountryGuid = computed(() => {
  if (props.origin === 'admin') return (country_guid.value as string) ?? ''
  return props.initialValues?.country?.guid ?? ''
})

const { data: documentTypesData, isLoading: isLoadingDocTypes } = useDocumentTypes(documentTypesCountryGuid)
```

**Cambio 3 — rama tenant del `watch(() => props.initialValues, ...)` (reemplaza líneas 81-87):**

Antes:
```ts
    } else {
      setValues({
        name:        vals.name ?? '',
        pdf_title:   vals.pdf_title ?? null,
        pdf_subtitle: vals.pdf_subtitle ?? null,
      })
    }
```

Después:
```ts
    } else {
      setValues({
        name:                 vals.name ?? '',
        document_type_guid:   vals.document_type?.guid ?? '',
        tax_id:               vals.tax_id ?? '',
        registration_number:  vals.registration_number ?? null,
        pdf_title:            vals.pdf_title ?? null,
        pdf_subtitle:         vals.pdf_subtitle ?? null,
      })
    }
```

**Cambio 4 — rama tenant de `onSubmit` (reemplaza líneas 112-116):**

Antes:
```ts
  if (props.origin === 'tenant') {
    const { name, pdf_title, pdf_subtitle } = values as { name: string; pdf_title: string | null; pdf_subtitle: string | null }
    emit('submit', { name, pdf_title, pdf_subtitle, contacts })
    return
  }
```

Después:
```ts
  if (props.origin === 'tenant') {
    const { name, document_type_guid, tax_id, registration_number, pdf_title, pdf_subtitle } = values as VetTenantUpdateForm
    emit('submit', { name, document_type_guid, tax_id, registration_number, pdf_title, pdf_subtitle, contacts })
    return
  }
```

**Cambio 5 — template: quitar el `v-if` de origen admin del bloque de tipo de documento / CUIT / matrícula (líneas 170-216).**

Antes (apertura y cierre del wrapper, líneas 170 y 216):
```html
      <template v-if="origin === 'admin'">
        <a-row :gutter="[16, 0]">
          ...
        </a-row>

        <a-form-item ...>
          ...
        </a-form-item>
      </template>
```

Después (eliminar el `<template v-if="origin === 'admin'">` y su `</template>` de cierre; el contenido interno queda igual, visible para ambos orígenes):
```html
      <a-row :gutter="[16, 0]">
        ...
      </a-row>

      <a-form-item ...>
        ...
      </a-form-item>
```

**Cambio 6 — corrección del `:disabled` del combo de tipo de documento (línea 186, ver DEC-03):**

Antes:
```html
              <a-select
                v-model:value="document_type_guid"
                v-bind="documentTypeGuidAttrs"
                :loading="isLoadingDocTypes"
                :options="documentTypeOptions"
                placeholder="Seleccioná el tipo de doc."
                allow-clear
                style="width: 100%"
                :disabled="!country_guid"
              />
```

Después:
```html
              <a-select
                v-model:value="document_type_guid"
                v-bind="documentTypeGuidAttrs"
                :loading="isLoadingDocTypes"
                :options="documentTypeOptions"
                placeholder="Seleccioná el tipo de doc."
                allow-clear
                style="width: 100%"
                :disabled="!documentTypesCountryGuid"
              />
```

No se toca el bloque "País" (líneas 146-167, sigue `v-if="origin === 'admin'"`) ni el `watch(country_guid, ...)` (líneas 60-64, sigue chequeando `origin === 'admin'` — correcto, ya que solo admin puede cambiar país y por tanto solo admin necesita resetear `document_type_guid` al cambiarlo).

### Archivos que NO requieren cambios (verificados)

- `front/src/modules/vets/types/vet.types.ts` — `VetUpdatePayload` ya incluye los 3 campos fiscales.
- `front/src/modules/vets/composables/useUpdateVetTenant.ts` — tipa el payload como `VetUpdatePayload` y lo reenvía sin whitelist de campos.
- `front/src/modules/vets/api/vets.api.ts` (`updateVetTenantApi`) — hace `PUT` del payload completo sin transformación.
- `front/src/modules/vets/pages/tenant/VetTenantEditPage.vue` — pasa `payload: unknown` directo a `mutate`, no requiere tocar.
- `front/src/modules/vets/composables/useDocumentTypes.ts` — acepta `Ref<string> | string`, ya compatible con el computed `documentTypesCountryGuid`.

### Tests a generar

El módulo `vets` no tiene tests frontend existentes (`front/src/modules/vets/**/*.spec.ts` no arroja resultados) — no hay convención de testing establecida en este módulo para replicar. Se deja fuera de alcance de este plan; si se decide introducir testing para el módulo, debería ser un esfuerzo aparte coordinado con `frontend-tester`, cubriendo como mínimo:
- `vetTenantUpdateSchema`: acepta payload solo con `name`, acepta payload con los 3 campos fiscales, rechaza `tax_id` > 30 caracteres.
- `VetForm.vue` con `origin="tenant"`: el bloque de tipo de documento/CUIT/matrícula se renderiza; el combo de tipo de documento no queda deshabilitado cuando `initialValues.country` está presente; `onSubmit` emite los 3 campos fiscales.

## Orden de implementación

1. Modificar `front/src/modules/vets/validators/vet.validator.ts` — extender `vetTenantUpdateSchema` (Cambio único de este archivo).
2. Modificar `front/src/modules/vets/components/forms/VetForm.vue`:
   1. Import de `VetTenantUpdateForm` (Cambio 1).
   2. Computed `documentTypesCountryGuid` y nuevo uso en `useDocumentTypes` (Cambio 2).
   3. Rama tenant de `setValues` en el watch de `initialValues` (Cambio 3).
   4. Rama tenant de `onSubmit` (Cambio 4).
   5. Quitar `v-if="origin === 'admin'"` del bloque de tipo de documento/CUIT/matrícula (Cambio 5).
   6. Corregir `:disabled` del combo de tipo de documento (Cambio 6).
3. Verificación manual: cargar `/vets/{guid}/editar` (ruta tenant) logueado como rol `vet`/`vet-assistant` con permiso de edición, confirmar que el combo de tipo de documento carga opciones y no aparece deshabilitado, editar CUIT y matrícula, guardar, y confirmar que el perfil (`VetResource`) refleja los cambios.
4. Correr `qa-frontend` sobre el diff (`VetForm.vue` + `vet.validator.ts`) antes de commitear, según la tabla de precedencia de agentes del proyecto.

## Riesgos y consideraciones

- **Multi-tenant**: sin riesgo — el endpoint ya resuelve `current_vet` desde el request autenticado (middleware que setea `attributes.current_vet`), no hay forma de que un tenant edite datos fiscales de otro vet a través de este flujo.
- **Multi-país**: sin riesgo nuevo — `document_type_guid` sigue validándose contra `document_types` filtrado implícitamente por el país fijo del vet (vía `documentTypesCountryGuid`); no se introduce lógica hardcodeada de Argentina.
- **Convención i18n incumplida (preexistente, no introducida por este plan)**: `backend-conventions`/`frontend-conventions` exigen `$t('clave')` en templates, pero `VetForm.vue` ya usa strings hardcodeados en español en todo el formulario (labels, placeholders, mensajes). Este plan mantiene el mismo patrón que el archivo ya usa para no mezclar estilos dentro del mismo componente; si se quiere corregir, es un refactor aparte que afecta también las partes admin del mismo archivo, fuera de alcance de este pedido puntual.
- **Deuda técnica ya documentada en `UpdateVetRequest`**: el comentario en el código (líneas 38-42) advierte que si se envía `tax_id` sin `document_type_guid`, no se valida el regex del tipo de documento. Con este cambio el tenant normalmente enviará ambos juntos (el form siempre incluye `document_type_guid` en el payload tenant), así que el caso "edge" documentado no debería activarse en el flujo normal de este formulario.
- **Validación de coherencia país/tipo de documento**: ni `UpdateVetRequest` ni `VetService::update()` validan que el `document_type_guid` recibido pertenezca al país del vet — solo `exists:document_types,guid`. Como el combo del FE ya filtra por `documentTypesCountryGuid`, en la práctica el usuario no puede seleccionar un tipo de otro país, pero un llamado directo a la API sí podría. Es una debilidad preexistente (ya aplica hoy para admin), no introducida por este cambio — se documenta pero no se corrige acá por ser cambio de backend fuera del alcance pedido.

## Pendientes / fuera de alcance

- Testing automatizado del módulo `vets` (no existe base para replicar convención).
- Validación backend de coherencia `document_type_guid` ↔ país del vet (mencionada en Riesgos).
- Corrección de i18n hardcodeado en `VetForm.vue` (preexistente, no introducida por este cambio).
