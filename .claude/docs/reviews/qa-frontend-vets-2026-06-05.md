# QA Review — Frontend: módulo vets
Fecha: 2026-06-05
Scope: `front/src/modules/vets/` completo (25 archivos) + verificaciones cruzadas contra `users/` y `roles/`
Type-check: PASA (0 errores)

## Resumen ejecutivo
- Críticos: 1
- Mayores: 2
- Menores: 3
- Estado: CON OBSERVACIONES

---

## Problemas críticos (bloquean merge)

### [C-01-BUG] — watch de country_guid borra document_type_guid en modo edición
**Archivo**: `front/src/modules/vets/components/forms/VetForm.vue` líneas 53-56 y 58-75

El `watch(country_guid, ...)` que limpia `document_type_guid` no tiene condición de guarda.
Cuando `setValues()` (línea 63) asigna `country_guid` al poblar el formulario en modo edición, el watch
se dispara y borra inmediatamente el `document_type_guid` que acaba de cargarse.
El resultado es que el campo "Tipo de documento" siempre aparece vacío al abrir el formulario de edición,
obligando al usuario a seleccionarlo de nuevo.

**Código actual**:
```typescript
// líneas 53-75
watch(country_guid, () => {
  document_type_guid.value = ''
})

watch(
  () => props.initialValues,
  (vals) => {
    if (props.mode === 'edit' && vals) {
      setValues({
        name: vals.name ?? '',
        country_guid: vals.country?.guid ?? '',       // <- dispara el watch de arriba
        document_type_guid: vals.document_type?.guid ?? '',  // <- se borra justo después
        ...
      } as VetCreateForm)
    }
  },
  { immediate: true, deep: true },
)
```

**Corrección**:
Agregar una bandera que indique que el cambio viene de una carga de datos, no de una interacción
del usuario. La forma más limpia es usar un flag reactivo:

```typescript
const isPopulating = ref(false)

watch(country_guid, () => {
  if (isPopulating.value) return   // no limpiar durante carga inicial
  document_type_guid.value = ''
})

watch(
  () => props.initialValues,
  (vals) => {
    if (props.mode === 'edit' && vals) {
      isPopulating.value = true
      setValues({
        name: vals.name ?? '',
        country_guid: vals.country?.guid ?? '',
        document_type_guid: vals.document_type?.guid ?? '',
        tax_id: vals.tax_id ?? '',
        registration_number: vals.registration_number ?? null,
        pdf_title: vals.pdf_title ?? null,
        pdf_subtitle: vals.pdf_subtitle ?? null,
      } as VetCreateForm)
      nextTick(() => { isPopulating.value = false })
    }
  },
  { immediate: true, deep: true },
)
```

Requiere importar `nextTick` de `vue` junto a `computed` y `watch`.

---

## Problemas mayores

### [M-09] — useForm con tipo genérico incorrecto en VetForm
**Archivo**: `front/src/modules/vets/components/forms/VetForm.vue` línea 29

`useForm` está tipado como `useForm<VetCreateForm>` aunque el componente opera en dos modos.
En modo `edit` el schema aplicado es `vetUpdateSchema`, cuyo tipo inferido es `VetUpdateForm`
(todos los campos son `optional`). El genérico incorrecto hace que TypeScript no detecte que
en modo `edit` los campos pueden ser `undefined`, y que el cast `as VetCreateForm` en `setValues`
(línea 71) sea un type-assertion forzado.

**Código actual**:
```typescript
const { errors, defineField, handleSubmit, setErrors, setValues, resetForm } = useForm<VetCreateForm>({
  validationSchema: computed(() => toTypedSchema(schema.value)),
})
```

**Corrección**:
```typescript
const { errors, defineField, handleSubmit, setErrors, setValues, resetForm } =
  useForm<VetCreateForm | VetUpdateForm>({
    validationSchema: computed(() => toTypedSchema(schema.value)),
  })
```

Y en el `setValues` de línea 71, reemplazar el cast:
```typescript
// antes
} as VetCreateForm)

// después
})
// sin cast — el tipo unión ya es compatible
```

### [M-09-b] — useDocumentTypes recibe computed con fallback a string vacío pero la query no tiene tipado explícito en el retorno
**Archivo**: `front/src/modules/vets/composables/useDocumentTypes.ts` línea 11

`useQuery` infiere el tipo de retorno de `listDocumentTypesApi`, que retorna `Promise<DocumentTypeItem[]>`.
Esto es correcto. Sin embargo, la `queryFn` se llama con `guidValue.value` que puede ser `''`
cuando no hay país seleccionado, y aunque `enabled` lo bloquea, si `enabled` es `false` el valor
de `data` es `undefined`. El composable no lo documenta y quien lo consume debe conocer ese detalle.
El patrón del proyecto es documentarlo en la firma de retorno explícita.

Esto es menor respecto al problema de tipado pero se lista aquí porque el `DocumentTypeItem[]` retornado
cuando `enabled === false` es `undefined`, no `[]`, y en `VetForm.vue` línea 50 se usa
`documentTypesData.value ?? []` correctamente — OK. Sin perjuicio, se recomienda agregar
`placeholderData: []` para que `data` nunca sea `undefined`:

```typescript
return useQuery({
  queryKey: ['document-types', guidValue],
  queryFn: () => listDocumentTypesApi(guidValue.value),
  enabled: computed(() => Boolean(guidValue.value)),
  staleTime: Infinity,
  placeholderData: [] as DocumentTypeItem[],   // <-- agregar
})
```

---

## Problemas menores

### [m-03] — Lógica de limpieza del formulario acoplada al componente VetForm en lugar de al composable
**Archivo**: `front/src/modules/vets/components/forms/VetForm.vue` líneas 53-56

La regla de negocio "al cambiar país se limpia el tipo de documento" está directamente en el template
component. En los módulos de referencia, la lógica de side-effects entre campos está en composables
(`useVetFilters`, `useUserFilters`). No es bloqueante porque el formulario es el único consumidor
de esta lógica, pero si en el futuro hubiera otro componente que reutilice estos campos, la lógica
no sería reutilizable.

### [m-04] — Nombre useVets aplica a una lista pero devuelve paginado con posible confusión de naming
**Archivo**: `front/src/modules/vets/composables/useVets.ts`

El composable sigue el patrón plural correcto para listas. Sin problema real — es conforme.
(Nota: sí hay una inconsistencia leve: `useVets` acepta `filters = {}` como valor por defecto en
la firma — línea 15 — mientras que `useUsers` no tiene valor por defecto. Es cosmético pero
genera una firma diferente sin necesidad.)

**Código actual**:
```typescript
export function useVets(filters: Ref<VetFilters> | VetFilters = {}) {
```

**Corrección** (para alinear con `useUsers`):
```typescript
export function useVets(filters: Ref<VetFilters> | VetFilters) {
```

### [m-03-b] — VetsListPage usa `<button>` HTML crudo en EmptyState en lugar de BaseButton
**Archivo**: `front/src/modules/vets/pages/VetsListPage.vue` líneas 62-65

`RolesPage.vue` (módulo de referencia) también usa `<button>` crudo en el `EmptyState`, por lo que
esto es una inconsistencia de proyecto heredada, no exclusiva del módulo vets. Se documenta pero
no bloquea.

**Código actual**:
```html
<button class="vl-btn-primary mt-3" @click="router.push('/admin/vets/new')">
  <PlusOutlined /> Crear primera veterinaria
</button>
```

**Corrección** (alinear con el átomo `BaseButton`):
```html
<BaseButton @click="router.push('/admin/vets/new')">
  <template #icon><PlusOutlined /></template>
  Crear primera veterinaria
</BaseButton>
```
Y eliminar las clases `.vl-btn-primary` y `.mt-3` del bloque `<style scoped>`.

---

## Verificaciones cruzadas

- [x] **Types vs Resource backend**: Los campos de `VetItem` (guid, name, slug, tax_id, registration_number, logo_path, pdf_title, pdf_subtitle, validated_at, suspended_at, is_active, country, document_type, validated_by, created_at) son coherentes con un Resource de veterinaria estándar. Sin spec disponible para contrastar exactamente.
- [x] **Schema Zod vs Form Request**: `vetCreateSchema` valida name (max 150), country_guid, document_type_guid, tax_id (max 30), registration_number (max 50), logo_path (max 500), pdf_title/subtitle (max 150). Valores razonables para un Form Request de creación.
- [x] **Rutas registradas en router/index.ts**: OK — `vetsRoutes` está importado y registrado en el bloque de children del AppLayout con `authGuard` heredado en `beforeEnter`. Línea 9 (import) y línea 30 (spread).
- [x] **Claves i18n**: No aplica — el proyecto no usa i18n en módulos de negocio (ni `users` ni `roles` lo usan). Los strings están en castellano directamente en los templates.
- [x] **Query invalidation por cada mutation**:
  - `useCreateVet`: invalida `['vets']` — OK
  - `useUpdateVet`: invalida `['vets']` y `['vet', guid]` — OK
  - `useValidateVet`: invalida `['vets']` y `['vet', guid]` — OK
  - `useSuspendVet`: invalida `['vets']` y `['vet', guid]` — OK
  - `useUnsuspendVet`: invalida `['vets']` y `['vet', guid]` — OK
- [x] **PermissionGuard en acciones de escritura**:
  - Botón "Nueva veterinaria" en lista: cubierto (`vets.create`) — OK
  - Botón "Editar" en tabla: cubierto (`vets.update`) — OK
  - Botón "Editar" en página detalle: cubierto (`vets.update`) — OK
  - Acciones validar/suspender/reactivar en `VetActionButtons`: cubiertas por `PermissionGuard permission="vets.validate"` — OK
  - Observación: las rutas `/admin/vets/new` y `/admin/vets/:guid/edit` no tienen `beforeEnter` con guard de permiso específico (solo heredan `authGuard`). Esto es consistente con el patrón de `roles/` que tampoco lo tiene, así que no es una violación de convención del proyecto.

---

## Resultado type-check

```
> vue-tsc --noEmit
(sin output — 0 errores)
```

---

## Archivos revisados

- `front/src/modules/vets/types/vet.types.ts`
- `front/src/modules/vets/types/vet.enums.ts`
- `front/src/modules/vets/api/vets.api.ts`
- `front/src/modules/vets/api/vets.mapper.ts`
- `front/src/modules/vets/validators/vet.validator.ts`
- `front/src/modules/vets/stores/vets-ui.store.ts`
- `front/src/modules/vets/composables/useVets.ts`
- `front/src/modules/vets/composables/useVet.ts`
- `front/src/modules/vets/composables/useCountries.ts`
- `front/src/modules/vets/composables/useDocumentTypes.ts`
- `front/src/modules/vets/composables/useCreateVet.ts`
- `front/src/modules/vets/composables/useUpdateVet.ts`
- `front/src/modules/vets/composables/useValidateVet.ts`
- `front/src/modules/vets/composables/useSuspendVet.ts`
- `front/src/modules/vets/composables/useUnsuspendVet.ts`
- `front/src/modules/vets/components/VetStatusBadge.vue`
- `front/src/modules/vets/components/VetActionButtons.vue`
- `front/src/modules/vets/components/VetFilters.vue`
- `front/src/modules/vets/components/VetsTable.vue`
- `front/src/modules/vets/components/forms/VetForm.vue`
- `front/src/modules/vets/pages/VetsListPage.vue`
- `front/src/modules/vets/pages/VetCreatePage.vue`
- `front/src/modules/vets/pages/VetEditPage.vue`
- `front/src/modules/vets/pages/VetDetailPage.vue`
- `front/src/modules/vets/router/vets.routes.ts`
- `front/src/router/index.ts` (verificación cruzada)
- `front/src/core/api/http.ts` (verificación de cliente HTTP)
- `front/src/modules/users/` (referencia)
- `front/src/modules/roles/` (referencia)
