<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import RolePermissionsSelector from './RolePermissionsSelector.vue'
import { createRoleSchema, updateRoleSchema, type RoleFormValues, type RoleUpdateFormValues } from '../../validators/role.validator'
import type { PermissionGroup, RoleType } from '../../types/role.types'

const props = withDefaults(
  defineProps<{
    initialValues?: Partial<RoleFormValues>
    permissionGroups: PermissionGroup[]
    loadingPermissions?: boolean
    loading?: boolean
    saveLabel?: string
    isProtected?: boolean
    fieldErrors?: Record<string, string> | null
    roleType?: RoleType
    hideFooter?: boolean
  }>(),
  { loading: false, loadingPermissions: false, isProtected: false, hideFooter: false },
)

const emit = defineEmits<{
  submit: [values: RoleFormValues | RoleUpdateFormValues]
}>()

const isTenantRole = computed(() => props.roleType === 'tenant')

const activeSchema = computed(() =>
  isTenantRole.value ? updateRoleSchema : createRoleSchema,
)

const { handleSubmit, defineField, errors, setValues, setErrors } = useForm({
  validationSchema: computed(() => toTypedSchema(activeSchema.value)),
  initialValues: {
    name: props.initialValues?.name ?? '',
    permissions: props.initialValues?.permissions ?? [],
  },
})

watch(
  () => props.initialValues,
  (vals) => {
    if (vals) setValues({ name: vals.name ?? '', permissions: vals.permissions ?? [] })
  },
)

watch(() => props.fieldErrors, (errors) => {
  setErrors(errors ?? {})
})

const [name, nameAttrs] = defineField('name')
const [permissionsField] = defineField('permissions')
const permissions = computed({
  get: () => (permissionsField.value ?? []) as string[],
  set: (val: string[]) => { permissionsField.value = val },
})

const onSubmit = handleSubmit((values) => emit('submit', values))

defineExpose({ submit: onSubmit })
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">

      <FormSection compact>
    <a-row :gutter="12">
      <a-col :xs="24">        
        <a-form-item
          label="Nombre del rol"
          :validate-status="errors.name ? 'error' : ''"
          :help="errors.name ?? ''"
        >
          <a-input v-model:value="name" v-bind="nameAttrs" placeholder="Ej: supervisor, auditor..." :disabled="!isTenantRole""/>
        </a-form-item>  
                     </a-col>

    </a-row>  
      </FormSection>
  

        <FormSection
          compact
          title="Permisos"
          subtitle="Seleccioná los permisos que tendrá este rol."
        >
    <a-row :gutter="12">
      <a-col :xs="24">        
          <RolePermissionsSelector
            v-model="permissions"
            :permission-groups="permissionGroups"
            :loading="loadingPermissions"
          />
          <span v-if="errors.permissions" class="rf-error">{{ errors.permissions }}</span>
      </a-col>
    </a-row>          
        </FormSection>


      

    <FormFooter
      v-if="!hideFooter"
      :loading="loading"
      cancel-to="/roles"
      :save-label="saveLabel ?? 'Guardar'"
    />
  </a-form>
</template>

<style scoped>
.rf-layout { display: flex; flex-direction: column; gap: 20px; }
.rf-main   { display: flex; flex-direction: column; gap: 20px; }
.rf-field  { display: flex; flex-direction: column; gap: 6px; }

.rf-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--dt-text, #C8E2EF);
}

.rf-input {
    padding:       10px 14px;
    border-radius: var(--dt-radius-md, 10px);
    border:        1px solid var(--dt-border, rgba(26,229,160,0.15));
    background:    var(--dt-header);
    color:         var(--dt-text, #C8E2EF);
    font-family:   'Figtree', system-ui, sans-serif;
    font-size:     14px;
    outline:       none;
    transition:    border-color 0.2s, box-shadow 0.2s;
    width:         100%;
    max-width:     420px;
}

.rf-input:focus {
    border-color: var(--dt-accent, #1AE5A0);
    box-shadow:   0 0 0 3px rgba(26, 229, 160, 0.1);
}
.rf-input.is-error { border-color: #FF5A6A; }
.rf-input:disabled { opacity: 0.5; cursor: default; }

.rf-error {
  font-size: 12px;
  color: #FF5A6A;
}

.rf-tenant-note {
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
  font-style: italic;
}
</style>
