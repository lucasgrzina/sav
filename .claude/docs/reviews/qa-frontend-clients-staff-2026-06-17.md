# QA Review — Frontend: TKT-003 Client Staff (módulo clients/)
Fecha: 2026-06-17
Scope: api/client-staff.api.ts, types/client.types.ts, composables admin (6 archivos), composables tenant (10 archivos), components (5 archivos + 2 auxiliares), pages (3 nuevas + 2 modificadas), routers (2 archivos)
Type-check: PASA (0 errores)

## Resumen ejecutivo
- Críticos: 0
- Mayores: 3
- Menores: 2
- Estado: CON OBSERVACIONES

---

## Problemas mayores

### [M-01 / M-04] — String de debug hardcodeado en ClientStaffTable, más uso de elemento HTML crudo en LookupForm

---

### [MAYOR-1] — String hardcodeado con texto de debug en ClientStaffTable.vue

**Archivo**: `front/src/modules/clients/components/ClientStaffTable.vue` línea 58

**Descripción**: La etiqueta de estado "Bloqueado" tiene el sufijo `(client)` que es texto de debug/desarrollo que nunca debería llegar al código. Viola M-01 (string hardcodeado) y contamina la UI de producción.

**Código actual**:
```html
<a-tag v-if="record.blocked_at" color="error">Bloqueado (client)</a-tag>
```

**Corrección**:
```html
<a-tag v-if="record.blocked_at" color="error">Bloqueado</a-tag>
```

Referencia: `VetStaffTable.vue` línea 58 usa `Bloqueado (vet)` con el mismo problema — ese también debe corregirse en su ticket correspondiente.

---

### [MAYOR-2] — `useUpdateClientStaff` y `useAdminUpdateClientStaff` sin patrón fieldErrors/generalError/resetErrors

**Archivos**:
- `front/src/modules/clients/composables/useUpdateClientStaff.ts` (todo el archivo)
- `front/src/modules/clients/composables/admin/useAdminUpdateClientStaff.ts` (todo el archivo)

**Descripción**: Todas las mutaciones de escritura del módulo implementan `fieldErrors`, `generalError` y `resetErrors` para que el formulario que las consume pueda mostrar errores de validación del backend. `useUpdateClientStaff` y `useAdminUpdateClientStaff` omiten este patrón. El resultado es que si el backend devuelve un 422 con errores de campo al guardar el perfil de un miembro, el error se muestra como notificación toast pero el formulario no señala qué campo está mal.

**Código actual** (`useUpdateClientStaff.ts`):
```typescript
export function useUpdateClientStaff(vetGuid: MaybeRef<string>, clientGuid: MaybeRef<string>) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  // ...
  const mutation = useMutation({
    // ...
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al actualizar el perfil.')
    },
  })

  return mutation  // sin fieldErrors, generalError, resetErrors
}
```

**Corrección** (mismo patrón que `useAssignClientStaff.ts`):
```typescript
import { ref, computed, toValue } from 'vue'

export function useUpdateClientStaff(vetGuid: MaybeRef<string>, clientGuid: MaybeRef<string>) {
  const queryClient        = useQueryClient()
  const { success, error } = useNotification()
  const vGuid              = computed(() => toValue(vetGuid))
  const cGuid              = computed(() => toValue(clientGuid))
  const fieldErrors        = ref<Record<string, string> | null>(null)
  const generalError       = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ profileGuid, payload }: { profileGuid: string; payload: UpdateClientStaffPayload }) =>
      updateClientStaffApi(vGuid.value, cGuid.value, profileGuid, payload),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: (_, vars) => {
      queryClient.invalidateQueries({ queryKey: ['client-staff', vGuid.value, cGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['client-staff-member', vGuid.value, cGuid.value, vars.profileGuid] })
      success('Perfil actualizado correctamente.')
    },
    onError: (err: unknown) => {
      const apiError     = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar el perfil.'
      if (apiError.message) error(apiError.message)
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
```

Aplicar el mismo patrón a `useAdminUpdateClientStaff.ts`.

Nota: `ClientStaffEditForm.vue` actualmente no consume `fieldErrors` porque no lo recibe como prop — esto es consecuencia del mismo problema. Una vez que los composables los exponen, el formulario debería pasárselos a los `a-form-item` correspondientes.

---

### [MAYOR-3] — `useChangeClientStaffRole` sin patrón fieldErrors/generalError/resetErrors

**Archivo**: `front/src/modules/clients/composables/useChangeClientStaffRole.ts` (todo el archivo)

**Descripción**: Mismo problema que MAYOR-2. La mutación de cambio de rol omite el patrón de manejo de errores de campo. Si el backend devuelve un 422 (rol inválido, por ejemplo), el error se pierde en el toast sin que el select quede marcado.

**Código actual**:
```typescript
export function useChangeClientStaffRole(vetGuid: string, clientGuid: string) {
  // ...
  const mutation = useMutation({
    // ...
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      error(apiError.message ?? 'Error al cambiar el rol.')
    },
  })

  return mutation  // sin fieldErrors, generalError, resetErrors
}
```

**Corrección**:
```typescript
export function useChangeClientStaffRole(vetGuid: string, clientGuid: string) {
  const queryClient        = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors        = ref<Record<string, string> | null>(null)
  const generalError       = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ profileGuid, roleGuid }: { profileGuid: string; roleGuid: string }) =>
      changeClientStaffRoleApi(vetGuid, clientGuid, profileGuid, roleGuid),
    onMutate: () => {
      fieldErrors.value  = null
      generalError.value = null
    },
    onSuccess: (_, vars) => {
      queryClient.invalidateQueries({ queryKey: ['client-staff', vetGuid, clientGuid] })
      queryClient.invalidateQueries({ queryKey: ['client-staff-member', vetGuid, clientGuid, vars.profileGuid] })
      success('Rol actualizado correctamente.')
    },
    onError: (err: unknown) => {
      const apiError     = parseApiError(err)
      fieldErrors.value  = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al cambiar el rol.'
      if (apiError.message) error(apiError.message)
    },
  })

  function resetErrors(): void {
    fieldErrors.value  = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}
```

---

## Problemas menores

### [MENOR-1] — Botón "Buscar" usa `<a-button>` en lugar de `<BaseButton>`

**Archivo**: `front/src/modules/clients/components/forms/ClientStaffLookupForm.vue` línea 125

**Descripción**: El botón de búsqueda de email usa `<a-button>` directamente. El proyecto tiene `<BaseButton>` como átomo equivalente (M-04). Los botones de "Cancelar" en `ClientStaffAssignForm.vue` (línea 92) y `ClientStaffNewForm.vue` (línea 131) tienen el mismo problema.

**Código actual**:
```html
<a-button
  type="primary"
  size="large"
  :loading="state.status === 'searching'"
  :disabled="!emailInput.trim()"
  @click="handleSearch"
>
  <template #icon><SearchOutlined /></template>
  Buscar
</a-button>
```

**Corrección**:
```html
<BaseButton
  type="primary"
  size="large"
  :loading="state.status === 'searching'"
  :disabled="!emailInput.trim()"
  @click="handleSearch"
>
  <template #icon><SearchOutlined /></template>
  Buscar
</BaseButton>
```

Aplicar lo mismo a los botones "Cancelar" y "Incorporar al equipo" / "Crear usuario e incorporar" en `ClientStaffAssignForm.vue` y `ClientStaffNewForm.vue`.

---

### [MENOR-2] — `useAdminClientStaff` usa `MaybeRef` pero `useAdminAssignClientStaff` recibe `string` plano

**Archivo**: `front/src/modules/clients/composables/admin/useAdminAssignClientStaff.ts` línea 8

**Descripción**: `useAdminClientStaff` acepta `MaybeRef<string>` para ser reactivo, pero los composables de mutación admin (`useAdminAssignClientStaff`, `useAdminChangeClientStaffRole`, `useAdminRemoveClientStaff`) reciben `clientGuid: string` plano. Actualmente esto no genera bug porque `AdminClientStaffSection.vue` pasa `props.clientGuid` que es fijo, pero es inconsistente con el patrón del resto del módulo y puede causar problemas si el componente que los consume cambia el guid.

No se requiere cambio inmediato si no existe caso de uso de guid reactivo en admin, pero se recomienda unificar a `MaybeRef<string>` para consistencia con el patrón establecido en `useAdminUpdateClientStaff` y todos los composables tenant.

---

## Verificaciones cruzadas

- [x] **Query keys admin correctas**: `['admin-client-staff', clientGuid]` — OK. Matches entre `useAdminClientStaff` y todos los `invalidateQueries` correspondientes.
- [x] **Query keys tenant correctas**: `['client-staff', vetGuid, clientGuid]` — OK. Matches entre `useClientStaff` y todos los `invalidateQueries` correspondientes.
- [x] **Query key member admin**: `['admin-client-staff-member', cGuid, pGuid]` — OK, invalidado correctamente en `useAdminUpdateClientStaff`.
- [x] **Query key member tenant**: `['client-staff-member', vGuid, cGuid, pGuid]` — OK, invalidado correctamente en `useUpdateClientStaff` y `useChangeClientStaffRole`.
- [x] **Patrón SAV en mutations** (fieldErrors + generalError + resetErrors): PROBLEMA — `useUpdateClientStaff`, `useAdminUpdateClientStaff`, `useChangeClientStaffRole` no lo implementan (ver MAYOR-2 y MAYOR-3).
- [x] **Rutas registradas en router/index.ts**: OK. `clientsRoutes` y `adminClientsRoutes` están importados y registrados.
- [x] **Orden de rutas en clients.routes.ts**: OK. `/staff/new` y `/staff/:profileGuid/edit` están antes que `/:guid`.
- [x] **Orden de rutas en admin-clients.routes.ts**: OK. `/staff/:profileGuid/editar` está antes que `/:guid`.
- [x] **Lazy loading en todas las rutas**: OK. Todas usan `() => import(...)`.
- [x] **authGuard en rutas**: OK. Las rutas tenant van bajo el layout `/` con `beforeEnter: authGuard`. Las rutas de staff de clients van bajo el mismo `AppLayout` con authGuard. Correcto — difieren del patrón `vetTenantGuard` intencionalmente porque son rutas de gestión dentro del panel general, no del panel tenant.
- [x] **ClientDetailPage usa ClientStaffSection**: OK, línea 7 y línea 116.
- [x] **AdminClientDetailPage tiene tab Staff con AdminClientStaffSection**: OK, líneas 6 y 89.
- [x] **ClientStaffSection tiene botón agregar, AdminClientStaffSection no**: OK. `ClientStaffSection.vue` tiene el botón envuelto en `PermissionGuard`. `AdminClientStaffSection.vue` no tiene botón de agregar.
- [x] **useClientRoles usa CLIENT_STAFF_ROLES del types file**: OK, línea 5 y 16 de `useClientRoles.ts`.
- [x] **Props tipadas con defineProps generic**: OK en todos los componentes.
- [x] **`<script setup lang="ts">`**: OK en todos los componentes y páginas.
- [x] **Imports correctos (sin OwnersSection)**: OK. No hay imports fantasma.
- [x] **Claves i18n**: El proyecto no usa i18n activamente en este módulo (ningún `$t()` en ningún archivo del módulo clients ni vets). No aplica.
- [x] **PermissionGuard en acciones de escritura**: OK en `ClientStaffSection.vue` (botón crear) y `AdminClientStaffSection.vue` (editar y eliminar). La tabla tenant (`ClientStaffTable.vue`) no tiene guards en los botones de fila — consistente con el patrón de `VetStaffTable.vue`.

---

## Resultado type-check

```
> mi-proyecto-front@0.0.0 type-check
> vue-tsc --noEmit

(sin salida — 0 errores)
```

---

## Archivos revisados

- `front/src/modules/clients/api/client-staff.api.ts`
- `front/src/modules/clients/types/client.types.ts`
- `front/src/modules/clients/composables/admin/useAdminClientStaff.ts`
- `front/src/modules/clients/composables/admin/useAdminClientStaffMember.ts`
- `front/src/modules/clients/composables/admin/useAdminAssignClientStaff.ts`
- `front/src/modules/clients/composables/admin/useAdminChangeClientStaffRole.ts`
- `front/src/modules/clients/composables/admin/useAdminRemoveClientStaff.ts`
- `front/src/modules/clients/composables/admin/useAdminUpdateClientStaff.ts`
- `front/src/modules/clients/composables/useClientRoles.ts`
- `front/src/modules/clients/composables/useClientStaff.ts`
- `front/src/modules/clients/composables/useClientStaffMember.ts`
- `front/src/modules/clients/composables/useLookupClientStaff.ts`
- `front/src/modules/clients/composables/useCreateClientStaff.ts`
- `front/src/modules/clients/composables/useAssignClientStaff.ts`
- `front/src/modules/clients/composables/useChangeClientStaffRole.ts`
- `front/src/modules/clients/composables/useToggleClientStaffBlock.ts`
- `front/src/modules/clients/composables/useRemoveClientStaff.ts`
- `front/src/modules/clients/composables/useUpdateClientStaff.ts`
- `front/src/modules/clients/components/ClientStaffTable.vue`
- `front/src/modules/clients/components/ClientStaffSection.vue`
- `front/src/modules/clients/components/admin/AdminClientStaffSection.vue`
- `front/src/modules/clients/components/forms/ClientStaffEditForm.vue`
- `front/src/modules/clients/components/forms/ClientStaffLookupForm.vue`
- `front/src/modules/clients/components/forms/ClientStaffAssignForm.vue`
- `front/src/modules/clients/components/forms/ClientStaffNewForm.vue`
- `front/src/modules/clients/pages/ClientStaffCreatePage.vue`
- `front/src/modules/clients/pages/ClientEditStaffPage.vue`
- `front/src/modules/clients/pages/admin/AdminClientEditStaffPage.vue`
- `front/src/modules/clients/pages/ClientDetailPage.vue`
- `front/src/modules/clients/pages/admin/AdminClientDetailPage.vue`
- `front/src/modules/clients/router/clients.routes.ts`
- `front/src/modules/clients/router/admin-clients.routes.ts`
- `front/src/router/index.ts`
- `front/src/modules/vets/api/vet-staff.api.ts` (referencia)
- `front/src/modules/vets/components/VetStaffSection.vue` (referencia)
- `front/src/modules/vets/components/VetStaffTable.vue` (referencia)
- `front/src/modules/vets/composables/useAdminVetStaff.ts` (referencia)
- `front/src/modules/vets/composables/useVetStaff.ts` (referencia)
- `front/src/modules/vets/pages/tenant/VetStaffCreatePage.vue` (referencia)
- `front/src/modules/vets/pages/tenant/VetEditStaffPage.vue` (referencia)
