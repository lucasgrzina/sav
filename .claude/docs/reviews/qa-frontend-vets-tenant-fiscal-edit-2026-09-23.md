# QA Review — Frontend: Habilitación de edición de datos fiscales en perfil tenant (vets)

Fecha: 2026-09-23
Scope: `front/src/modules/vets/validators/vet.validator.ts` (extensión de `vetTenantUpdateSchema`), `front/src/modules/vets/components/forms/VetForm.vue` (computed `documentTypesCountryGuid`, watch de `initialValues`, `onSubmit`, template). Cross-check contra `front/src/modules/vets/types/vet.types.ts`, `front/src/modules/vets/api/vets.api.ts`, `front/src/modules/vets/composables/useUpdateVetTenant.ts`, `front/src/modules/vets/composables/useDocumentTypes.ts`, `front/src/modules/vets/pages/tenant/VetTenantEditPage.vue` y `back/app/Http/Requests/Vets/UpdateVetRequest.php` / `back/app/Http/Controllers/V1/VetController.php`.

## Resumen ejecutivo
- Críticos: 0
- Mayores: 1
- Menores: 0
- Estado: CON OBSERVACIONES

## Problemas críticos (bloquean merge)

Ninguno. Los tres puntos específicos pedidos se verificaron sin encontrar bugs:

1. **Reactividad de `documentTypesCountryGuid`**: correcta para ambos orígenes. Para `admin` sigue leyendo `country_guid.value` (el campo reactivo del form), para `tenant` lee `props.initialValues?.country?.guid`. Tracé el origen de `initialValues` en modo tenant: `VetTenantEditPage.vue` sólo monta `<VetForm>` dentro del `v-else` de `v-if="!vet"`, y `vet` viene de `vetStore.currentVet`, poblado por `useVetTenant()` → `useVetProfile(guid)` → `GET /v1/vets/{guid}` (`VetController::show`), que hace `$vet->load(['country', 'documentType', 'contacts'])`. Es decir, cuando `VetForm` se monta en modo tenant, `initialValues.country.guid` ya está disponible — el combo de tipo de documento no queda deshabilitado ni roto.
2. **`country_guid` no se reabrió para tenant**: confirmado. `vetTenantUpdateSchema` no declara `country_guid`, el bloque "País" del template sigue con `v-if="origin === 'admin'"` (sin tocar), y el branch tenant de `onSubmit` no lo incluye en el payload emitido.
3. **`onSubmit` para tenant vs. `VetUpdatePayload`**: el objeto emitido (`{ name, document_type_guid, tax_id, registration_number, pdf_title, pdf_subtitle, contacts }`) es asignable sin excedentes a `VetUpdatePayload` (todos sus campos son opcionales salvo los ausentes como `logo_path`, que también es opcional). `npm run type-check` (`vue-tsc --noEmit`) corre limpio, sin errores.

## Problemas mayores

### [Cross-check] — `tax_id` más restrictivo en el frontend que en el backend (heredado, ahora también en `vetTenantUpdateSchema`)
**Archivo**: `front/src/modules/vets/validators/vet.validator.ts` línea 96-100 (y también línea 59-63 en `vetUpdateSchema`, sin tocar en este diff)
**Código actual**:
```ts
tax_id: z
  .string()
  .min(1, 'El CUIT/identificador fiscal es requerido')
  .max(30, 'Máximo 30 caracteres')
  .optional(),
```
Backend (`UpdateVetRequest::rules()`, sin cambios):
```php
'tax_id' => ['sometimes', 'string', 'max:50', $this->taxIdRule()],
```
El Zod schema rechaza en el cliente un `tax_id` de entre 31 y 50 caracteres que el backend sí aceptaría. Esto ya era así en `vetUpdateSchema` (panel admin) antes de este cambio, por lo que **no es una regresión introducida por este diff** — pero al copiar la regla tal cual a `vetTenantUpdateSchema`, ahora el mismo límite incorrecto queda expuesto también en el self-service de la veterinaria, una superficie nueva. Como quedó copiado en los tres schemas (`create`, `update`, `tenant-update`), lo correcto es corregir la fuente y no perpetuar el desvío.
**Corrección**:
```ts
tax_id: z
  .string()
  .min(1, 'El CUIT/identificador fiscal es requerido')
  .max(50, 'Máximo 50 caracteres')
  .optional(),
```
Aplicar el mismo `max(50)` en `vetCreateSchema`, `vetUpdateSchema` y `vetTenantUpdateSchema` para que los tres queden alineados con `UpdateVetRequest`/`StoreVetRequest`. Si el equipo prefiere mantener `30` como tope de negocio real, hay que bajar el límite del lado backend en vez de dejarlos desincronizados.

## Problemas menores

Ninguno atribuible a este diff.

## Observaciones adicionales (no forman parte del checklist oficial)

- **Strings hardcodeados en `VetForm.vue`** (ej. "Tipo de documento", "CUIT / Identificador fiscal", "Número de matrícula / registro"): violan M-01 (`$t('clave')` en vez de texto plano), pero es deuda preexistente de todo el archivo — el diff sólo reubicó bloques que ya tenían ese texto hardcodeado, no introdujo strings nuevos. No lo marco como bloqueante de este cambio puntual, pero si se toca este archivo de nuevo conviene migrarlo a i18n.
- El bloque `setValues({...})` del watch de `initialValues` ahora duplica 5 de 6 campos entre las ramas `admin` y `tenant` (sólo difiere en `country_guid`). No es una violación de regla del checklist, pero es una oportunidad de simplificación menor (spread + override) si se vuelve a tocar el archivo.

## Verificaciones cruzadas

- Types (`VetUpdatePayload`) vs Resource backend (`VetResource`/`UpdateVetRequest`): **OK** — campos coinciden (`document_type_guid`, `tax_id`, `registration_number`, `pdf_title`, `pdf_subtitle`).
- Schema Zod (`vetTenantUpdateSchema`) vs `UpdateVetRequest`: **PROBLEMA** — ver hallazgo mayor arriba (`tax_id` max 30 vs 50). Resto de reglas (`document_type_guid` sometimes/optional, `registration_number` nullable, `pdf_title`/`pdf_subtitle` opcionales) consistentes.
- GUID como identificador: **OK** — `updateVetTenantApi(guid, payload)` pega a `PUT /v1/vets/{guid}`, sin `id` numérico en ningún punto tocado.
- `country_guid` no editable por tenant: **OK** — confirmado en schema, template y payload.
- Reactividad `documentTypesCountryGuid` para tenant: **OK** — depende de dato ya cargado por el store antes del mount de `VetForm`.
- `npm run type-check` (`vue-tsc --noEmit`): **OK** — sin errores.
- PermissionGuard en `VetTenantEditPage.vue`: no se tocó en este diff (el archivo no forma parte de los cambios revisados); no lo marco como hallazgo de esta revisión, pero si el criterio C-06 aplica a la edición de perfil propio del tenant, conviene confirmarlo en una revisión separada del módulo `vets` completo.

## Archivos revisados
- `front/src/modules/vets/validators/vet.validator.ts`
- `front/src/modules/vets/components/forms/VetForm.vue`
- `front/src/modules/vets/types/vet.types.ts`
- `front/src/modules/vets/api/vets.api.ts`
- `front/src/modules/vets/composables/useUpdateVetTenant.ts`
- `front/src/modules/vets/composables/useDocumentTypes.ts`
- `front/src/modules/vets/pages/tenant/VetTenantEditPage.vue`
- `front/src/modules/vets/composables/useVetTenant.ts`
- `front/src/core/stores/vet.store.ts`
- `back/app/Http/Requests/Vets/UpdateVetRequest.php`
- `back/app/Http/Controllers/V1/VetController.php`
