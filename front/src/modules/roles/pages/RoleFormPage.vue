<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/shared/PageHeader.vue'
import RoleForm from '../components/forms/RoleForm.vue'
import { useRole } from '../composables/useRole'
import { useCreateRole } from '../composables/useCreateRole'
import { useUpdateRole } from '../composables/useUpdateRole'
import { usePermissions } from '@/modules/permissions/composables/usePermissions'
import type { RoleFormValues, RoleUpdateFormValues } from '../validators/role.validator'
import type { PermissionGroup } from '../types/role.types'

const props = defineProps<{ guid?: string }>()

const router = useRouter()
const isEditing = computed(() => !!props.guid)
const pageTitle = computed(() => (isEditing.value ? 'Editar rol' : 'Nuevo rol'))
const pageSubtitle = computed(() => {
  if (!isEditing.value) return 'Creá un nuevo rol y asignale permisos.'
  if (role.value?.type === 'tenant') return 'Modificá los permisos del rol.'
  return 'Modificá el nombre y los permisos del rol.'
})

const { data: role, isLoading: loadingRole } = useRole(computed(() => props.guid ?? ''))
const { data: permissionsData, isLoading: loadingPerms } = usePermissions()

const createRole = useCreateRole()
const updateRole = useUpdateRole()

const permissionGroups = computed<PermissionGroup[]>(() => permissionsData.value ?? [])

const initialValues = computed(() => {
  if (!role.value) return undefined
  return {
    name: role.value.name,
    permissions: role.value.permissions.map((p) => p.guid),
  }
})

const isProtected = computed(() =>
  isEditing.value && ['super-admin', 'admin'].includes(role.value?.name ?? ''),
)

const isMutating = computed(
  () => createRole.isPending.value || updateRole.isPending.value,
)

const activeFieldErrors = computed(() =>
  isEditing.value ? updateRole.fieldErrors.value : createRole.fieldErrors.value,
)

const activeGeneralError = computed(() =>
  isEditing.value ? updateRole.generalError.value : createRole.generalError.value,
)

function handleSubmit(values: RoleFormValues | RoleUpdateFormValues) {
  if (isEditing.value) {
    const payload =
      role.value?.type === 'tenant'
        ? { permissions: values.permissions }
        : { name: (values as RoleFormValues).name, permissions: values.permissions }

    updateRole.mutate(
      { guid: props.guid!, payload },
      { onSuccess: () => router.push('/roles') },
    )
  } else {
    createRole.mutate(values as RoleFormValues, {
      onSuccess: () => router.push('/roles'),
    })
  }
}
</script>

<template>
  <div>
    <PageHeader :title="pageTitle" :subtitle="pageSubtitle" />

    <div v-if="isEditing && loadingRole" class="rp-loading">
      Cargando rol...
    </div>

    <template v-else>
      <div
        v-if="activeGeneralError && !activeFieldErrors"
        class="rp-error-alert"
      >
        {{ activeGeneralError }}
      </div>

      <RoleForm
        :initial-values="initialValues"
        :permission-groups="permissionGroups"
        :loading-permissions="loadingPerms"
        :loading="isMutating"
        :save-label="isEditing ? 'Actualizar' : 'Crear rol'"
        :is-protected="isProtected"
        :field-errors="activeFieldErrors"
        :role-type="isEditing ? role?.type : undefined"
        @submit="handleSubmit"
      />
    </template>
  </div>
</template>

<style scoped>
.rp-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.rp-error-alert {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 20px;
}
</style>
